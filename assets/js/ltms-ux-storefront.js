(function () {
    'use strict';

    // ── Namespace LTMS.UX ──────────────────────────────────────
    window.LTMS = window.LTMS || {};
    LTMS.UX = LTMS.UX || {};

    // CONFIG del bundle shared (definido en ltms-ux-shared.js).
    const CONFIG = (LTMS.UX && LTMS.UX.config) || {};


    // ── Aliases a funciones del bundle shared (ltms-ux-shared.js) ──
    const toast = LTMS.UX.toast;
    const toastSuccess = LTMS.UX.toastSuccess;
    const toastError = LTMS.UX.toastError;
    const toastWarning = LTMS.UX.toastWarning;
    const toastInfo = LTMS.UX.toastInfo;
    const trapFocus = LTMS.UX.trapFocus;
    const announce = LTMS.UX.announce;
    const escapeHtml = LTMS.UX.escapeHtml;
    const debounce = LTMS.UX.debounce;
    const confirmDialog = LTMS.UX.confirmDialog;
    const formatCurrency = LTMS.UX.formatCurrency;
    const formatDate = LTMS.UX.formatDate;
    const formatNumber = LTMS.UX.formatNumber;
    const celebrateConfetti = LTMS.UX.celebrateConfetti;
    const showOrderSuccess = LTMS.UX.showOrderSuccess;
    const renderEmptyState = LTMS.UX.renderEmptyState;
    const createCountdown = LTMS.UX.createCountdown;
    const renderStockIndicator = LTMS.UX.renderStockIndicator;
    const createStarRating = LTMS.UX.createStarRating;
    const openPrintPreview = LTMS.UX.openPrintPreview;


    // ═══════════════════════════════════════════════════════════
    // SECCIONES STOREFRONT (tienda pública: home, shop, producto,
    // carrito, checkout)
    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js
    // ═══════════════════════════════════════════════════════════

    // 53. CART DRAWER — Carrito deslizable lateral
    // ═══════════════════════════════════════════════════════════

    /**
     * Carrito de compras tipo drawer que se desliza desde la derecha.
     * Permite ver y modificar el carrito sin abandonar la página.
     */

    let cartDrawerState = {
        drawer: null,
        overlay: null,
        cleanup: null,
    };

    function openCartDrawer() {
        if (cartDrawerState.drawer) return;

        cartDrawerState.overlay = document.createElement('div');
        cartDrawerState.overlay.className = 'ltms-cart-drawer-overlay';

        cartDrawerState.drawer = document.createElement('aside');
        cartDrawerState.drawer.className = 'ltms-cart-drawer';
        cartDrawerState.drawer.setAttribute('role', 'dialog');
        cartDrawerState.drawer.setAttribute('aria-modal', 'true');
        cartDrawerState.drawer.setAttribute('aria-label', 'Carrito de compras');

        cartDrawerState.drawer.innerHTML = `
            <div class="ltms-cart-drawer-header">
                <h3 class="ltms-cart-drawer-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    Tu carrito
                </h3>
                <button type="button" class="ltms-cart-drawer-close" aria-label="Cerrar carrito">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ltms-cart-drawer-body" id="ltms-cart-drawer-items">
                <div class="ltms-cart-drawer-loading">
                    <div class="ltms-spinner-lg"></div>
                </div>
            </div>
            <div class="ltms-cart-drawer-footer">
                <div class="ltms-cart-drawer-subtotal">
                    <span>Subtotal</span>
                    <span class="ltms-cart-drawer-subtotal-value" id="ltms-cart-subtotal">$0</span>
                </div>
                <div class="ltms-cart-drawer-shipping">
                    <span>Envío</span>
                    <span id="ltms-cart-shipping">A calcular</span>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-full ltms-cart-drawer-checkout" id="ltms-cart-checkout-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Finalizar compra
                </button>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-full ltms-cart-drawer-continue">
                    Seguir comprando
                </button>
            </div>
        `;

        document.body.appendChild(cartDrawerState.overlay);
        document.body.appendChild(cartDrawerState.drawer);
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => {
            cartDrawerState.overlay.classList.add('visible');
            cartDrawerState.drawer.classList.add('open');
        });

        // Event handlers
        cartDrawerState.drawer.querySelector('.ltms-cart-drawer-close').addEventListener('click', closeCartDrawer);
        cartDrawerState.drawer.querySelector('.ltms-cart-drawer-continue').addEventListener('click', closeCartDrawer);
        cartDrawerState.overlay.addEventListener('click', closeCartDrawer);

        cartDrawerState.drawer.querySelector('#ltms-cart-checkout-btn').addEventListener('click', () => {
            // v2.9.50: Usar la URL de checkout que viene del backend (data.checkout_url)
            // en vez de hardcoded /checkout/ — algunos sitios usan /finalizar-compra/ u otros slugs.
            // Fallback a /checkout/ si no hay data.
            const checkoutUrl = (cartDrawerState.checkoutUrl) || '/checkout/';
            window.location.href = checkoutUrl;
        });

        // Keyboard
        const keyHandler = (e) => { if (e.key === 'Escape') closeCartDrawer(); };
        document.addEventListener('keydown', keyHandler);

        cartDrawerState.cleanup = () => {
            document.removeEventListener('keydown', keyHandler);
        };

        // Load cart contents
        loadCartContents();
        announce('Carrito abierto');
    }

    function closeCartDrawer() {
        if (!cartDrawerState.drawer) return;
        if (cartDrawerState.cleanup) cartDrawerState.cleanup();

        cartDrawerState.overlay.classList.remove('visible');
        cartDrawerState.drawer.classList.remove('open');
        document.body.style.overflow = '';

        setTimeout(() => {
            if (cartDrawerState.overlay && cartDrawerState.overlay.parentNode) cartDrawerState.overlay.parentNode.removeChild(cartDrawerState.overlay);
            if (cartDrawerState.drawer && cartDrawerState.drawer.parentNode) cartDrawerState.drawer.parentNode.removeChild(cartDrawerState.drawer);
            cartDrawerState.drawer = null;
            cartDrawerState.overlay = null;
            cartDrawerState.cleanup = null;
        }, 300);
    }

    function loadCartContents() {
        const itemsContainer = document.querySelector('#ltms-cart-drawer-items');
        if (!itemsContainer) return;

        // Task 67-A / UX-CART-1 FIX: previously POSTed to the
        // `woocommerce_get_cart_contents` AJAX action — which does NOT exist
        // in WooCommerce core (WC only exposes `get_refreshed_fragments`,
        // `add_to_cart`, `remove_from_cart`, `apply_coupon`…). Every request
        // 404'd / returned `-1`, the success branch never ran, and the drawer
        // always showed "Tu carrito está vacío" even when the cart had items.
        //
        // We now call the LTMS custom endpoint `ltms_get_cart` (registered in
        // class-ltms-frontend-checkout-handler.php) which returns structured
        // cart contents in the exact shape renderCartItems() expects. Falls
        // back to WC's `get_refreshed_fragments` (HTML fragments) only as a
        // last resort when `ltmsUX` is unavailable (older pages cached).

        const sendGetCart = () => {
            const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof wc_cart_fragments_params !== 'undefined' && wc_cart_fragments_params.ajax_url)
                || (typeof ltmsCheckout !== 'undefined' && ltmsCheckout.ajax_url)
                || '';
            if (!ajaxUrl) {
                renderCartEmpty(itemsContainer);
                return;
            }
            const body = new URLSearchParams();
            body.append('action', 'ltms_get_cart');
            if (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) {
                body.append('nonce', ltmsUX.nonce);
            }
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
            })
                .then((r) => r.json())
                .then((response) => {
                    if (response && response.success && response.data) {
                        // v2.9.50: Guardar checkout_url para el botón de checkout.
                        if (response.data.checkout_url) {
                            cartDrawerState.checkoutUrl = response.data.checkout_url;
                        }
                        renderCartItems(itemsContainer, response.data);
                    } else {
                        renderCartEmpty(itemsContainer);
                    }
                })
                .catch(() => {
                    // Final fallback: WC's get_refreshed_fragments returns HTML
                    // fragments, not structured data. We can't reliably parse
                    // items from it, so show the empty state with a link to the
                    // full cart page (renderCartEmpty already provides this).
                    renderCartEmpty(itemsContainer);
                });
        };

        if (typeof jQuery !== 'undefined') {
            // jQuery wrapper so we keep the same fail-hard semantics as the
            // original code (renderCartEmpty on any error).
            try {
                sendGetCart();
            } catch (e) {
                renderCartEmpty(itemsContainer);
            }
        } else if (typeof fetch !== 'undefined') {
            sendGetCart();
        } else {
            // No AJAX available — show empty state.
            setTimeout(() => renderCartEmpty(itemsContainer), 500);
        }
    }

    function renderCartItems(container, data) {
        if (!data.items || !data.items.length) {
            renderCartEmpty(container);
            return;
        }

        // v2.9.36: NO usar escapeHtml en price_formatted ni total_formatted
        // porque ya vienen sanitizados con wp_strip_all_tags() desde PHP.
        // escapeHtml convierte &#36; → &amp;#36; rompiendo los precios.
        container.innerHTML = data.items.map((item) => `
            <div class="ltms-cart-item" data-cart-item-key="${escapeHtml(item.key)}">
                <div class="ltms-cart-item-img">
                    ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '<div class="ltms-cart-item-no-img">📦</div>'}
                </div>
                <div class="ltms-cart-item-info">
                    <a href="${escapeHtml(item.product_url || '#')}" class="ltms-cart-item-name">${escapeHtml(item.name)}</a>
                    ${item.variation ? `<div class="ltms-cart-item-variation">${escapeHtml(item.variation)}</div>` : ''}
                    <div class="ltms-cart-item-price">${item.price_formatted || ''}</div>
                    <div class="ltms-cart-item-qty">
                        <button type="button" class="ltms-cart-qty-btn ltms-cart-qty-dec" data-key="${escapeHtml(item.key)}" aria-label="Disminuir">−</button>
                        <span class="ltms-cart-qty-value">${item.quantity}</span>
                        <button type="button" class="ltms-cart-qty-btn ltms-cart-qty-inc" data-key="${escapeHtml(item.key)}" aria-label="Aumentar">+</button>
                    </div>
                </div>
                <button type="button" class="ltms-cart-item-remove" data-key="${escapeHtml(item.key)}" aria-label="Eliminar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
        `).join('');

        const subtotalEl = document.querySelector('#ltms-cart-subtotal');
        if (subtotalEl && data.total_formatted) {
            // v2.9.50: Usar innerHTML (no textContent) porque total_formatted
            // viene de wc_price() sanitizado con wp_strip_all_tags() y contiene
            // entidades HTML como &#36; (símbolo $) y &nbsp; (espio).
            // textContent muestra estas entidades como texto literal (&#36;&nbsp;735.000)
            // en vez de renderizarlas ($ 735.000).
            subtotalEl.innerHTML = data.total_formatted;
        }

        // Bind qty buttons
        container.querySelectorAll('.ltms-cart-qty-inc').forEach((btn) => {
            btn.addEventListener('click', () => updateCartQty(btn.dataset.key, 1));
        });
        container.querySelectorAll('.ltms-cart-qty-dec').forEach((btn) => {
            btn.addEventListener('click', () => updateCartQty(btn.dataset.key, -1));
        });
        container.querySelectorAll('.ltms-cart-item-remove').forEach((btn) => {
            btn.addEventListener('click', () => removeCartItem(btn.dataset.key));
        });
    }

    function renderCartEmpty(container) {
        container.innerHTML = `
            <div class="ltms-cart-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3;margin-bottom:12px;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <p>Tu carrito está vacío</p>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" onclick="LTMS.UX.closeCartDrawer()">Explorar productos</button>
            </div>
        `;
        const subtotalEl = document.querySelector('#ltms-cart-subtotal');
        if (subtotalEl) subtotalEl.innerHTML = '$0';
    }

    function updateCartQty(key, change) {
        // v2.9.207: Defense-in-depth fallback. If the inline LTMS_CART script
        // (injected via output buffering) is present, it handles clicks via
        // capture-phase listener and this function is never called. But if SG
        // cache serves a stale HTML page without the inline script, this code
        // path becomes the active handler — so it must actually work.
        if (typeof window.LTMS_CART !== 'undefined' && window.LTMS_CART.updateQty) {
            window.LTMS_CART.updateQty(key, change);
            return;
        }
        // Legacy fallback (pre-v2.9.59): direct fetch via ltmsUX.
        const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || '/wp-admin/admin-ajax.php';
        const nonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) || '';
        const body = new URLSearchParams();
        body.append('action', 'ltms_drawer_update_qty');
        body.append('nonce', nonce);
        body.append('cart_item_key', key);
        // Need to fetch current qty from the DOM.
        const itemEl = document.querySelector('[data-cart-item-key="' + key + '"]');
        const qtySpan = itemEl ? itemEl.querySelector('.ltms-cart-qty-value') : null;
        const currentQty = qtySpan ? parseInt(qtySpan.textContent, 10) || 1 : 1;
        const newQty = Math.max(1, currentQty + change);
        body.append('qty', String(newQty));
        if (qtySpan) qtySpan.textContent = newQty;
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).then(r => r.json()).then(() => {
            if (typeof loadCartContents === 'function') loadCartContents();
        }).catch(() => {});
    }

    function removeCartItem(key) {
        // v2.9.207: Defense-in-depth fallback. Same logic as updateCartQty.
        if (typeof window.LTMS_CART !== 'undefined' && window.LTMS_CART.removeItem) {
            window.LTMS_CART.removeItem(key);
            return;
        }
        const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || '/wp-admin/admin-ajax.php';
        const nonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) || '';
        const body = new URLSearchParams();
        body.append('action', 'ltms_drawer_remove_item');
        body.append('nonce', nonce);
        body.append('cart_item_key', key);
        const itemEl = document.querySelector('[data-cart-item-key="' + key + '"]');
        if (itemEl) {
            itemEl.style.transition = 'opacity 0.2s, transform 0.2s';
            itemEl.style.opacity = '0';
            itemEl.style.transform = 'translateX(20px)';
            setTimeout(() => { if (itemEl.parentNode) itemEl.parentNode.removeChild(itemEl); }, 200);
        }
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).then(r => r.json()).then(() => {
            if (typeof loadCartContents === 'function') loadCartContents();
        }).catch(() => {});
    }

    /**
     * v2.9.52: Actualiza el badge de contador del carrito en el header.
     */
    function updateCartCount(count) {
        document.querySelectorAll('.ltms-sf-cart-count, .ltms-cart-count, .cart-count').forEach((el) => {
            el.textContent = count;
        });
    }

    function initCartDrawer() {
        // v2.9.208 — DECISIÓN ARQUITECTÓNICA: Cart drawer ELIMINADO.
        // Después de 4 versiones fallidas (v2.9.204 → v2.9.207) intentando
        // estabilizar el drawer en el entorno hostil de SiteGround, se elimina.
        // El icono del carrito ahora usa su href nativo (wc_get_cart_url())
        // y redirige a /cart donde WC maneja todo con AJAX nativo confiable.
        //
        // NO interceptar clicks del cart trigger — dejar que el navegador
        // siga el href natural hacia /cart.
        //
        // Mantenemos:
        //   - updateCartBadge() para refrescar el contador tras add-to-cart
        //   - El handler de +/- en el drawer (por si SG cache sirve HTML stale
        //     con drawer todavía presente, los botones deben seguir funcionando
        //     via LTMS_CART si está inyectado, o via fallback fetch).

        // v2.9.53: EVENT DELEGATION para botones del carrito (por si el drawer
        // sigue presente en HTML cacheado por SG).
        document.addEventListener('click', (e) => {
            const incBtn = e.target.closest('.ltms-cart-qty-inc');
            const decBtn = e.target.closest('.ltms-cart-qty-dec');
            const removeBtn = e.target.closest('.ltms-cart-item-remove');

            if (incBtn) {
                e.preventDefault();
                e.stopPropagation();
                updateCartQty(incBtn.dataset.key, 1);
                return;
            }
            if (decBtn) {
                e.preventDefault();
                e.stopPropagation();
                updateCartQty(decBtn.dataset.key, -1);
                return;
            }
            if (removeBtn) {
                e.preventDefault();
                e.stopPropagation();
                removeCartItem(removeBtn.dataset.key);
                return;
            }
        });

        // Actualizar contador del carrito cuando cambia
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_cart_totals', () => {
                if (cartDrawerState.drawer) loadCartContents();
            });
            jQuery(document.body).on('added_to_cart', () => {
                updateCartBadge();
            });
        }
    }

    /**
     * v2.9.49: Actualiza solo el badge de contador del carrito (sin AJAX).
     * Lee los fragments que WC ya devolvió en added_to_cart.
     */
    function updateCartBadge() {
        try {
            // WC añade el contador al HTML via fragments; solo leemos del DOM.
            const badge = document.querySelector('.ltms-cart-count, .cart-count, .elementor-menu-cart__toggle_button .elementor-button-icon-cart');
            // No forzar otra petición — el badge se actualiza solo cuando el
            // drawer se abre explícitamente.
        } catch (e) {}
    }

    LTMS.UX.openCartDrawer = openCartDrawer;
    LTMS.UX.closeCartDrawer = closeCartDrawer;

    // ═══════════════════════════════════════════════════════════
    // 57. COOKIE CONSENT — Banner GDPR
    // ═══════════════════════════════════════════════════════════

    /**
     * Banner de consentimiento de cookies que cumple GDPR.
     * Permite aceptar todo, rechazar todo o personalizar categorías.
     */

    function initCookieConsent() {
        // Verificar SIEMPRE la cookie ltms_cookie_consent (la leen PHP y JS).
        var cookieMatch = document.cookie.match(/(?:^|;\s*)ltms_cookie_consent=([^;]+)/);
        if (cookieMatch) return; // ya aceptó/rechazó
        try {
            const consent = localStorage.getItem('ltms-cookie-consent');
            if (consent) return; // compatibilidad con versiones anteriores
        } catch (e) {}

        // No mostrar en admin o login
        if (document.querySelector('.ltms-auth-container')) return;

        setTimeout(() => {
            const banner = document.createElement('div');
            banner.className = 'ltms-cookie-consent';
            banner.innerHTML = `
                <div class="ltms-cookie-content">
                    <div class="ltms-cookie-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="8" cy="9" r="1"/><circle cx="16" cy="10" r="1"/><circle cx="9" cy="15" r="1"/><circle cx="15" cy="16" r="1"/></svg>
                    </div>
                    <div class="ltms-cookie-text">
                        <strong>Usamos cookies</strong>
                        <p>Utilizamos cookies propias y de terceros para mejorar tu experiencia, analizar el tráfico y personalizar contenido. Puedes aceptar todas, rechazar no esenciales o personalizar.</p>
                    </div>
                </div>
                <div class="ltms-cookie-actions">
                    <button type="button" class="ltms-cookie-btn ltms-cookie-reject">Solo esenciales</button>
                    <button type="button" class="ltms-cookie-btn ltms-cookie-custom">Personalizar</button>
                    <button type="button" class="ltms-cookie-btn ltms-cookie-accept ltms-btn-primary">Aceptar todas</button>
                </div>
            `;

            document.body.appendChild(banner);
            requestAnimationFrame(() => banner.classList.add('visible'));

            const saveConsent = (prefs) => {
                try {
                    localStorage.setItem('ltms-cookie-consent', JSON.stringify({ ...prefs, date: Date.now() }));
                } catch (e) {}
                // Setear cookie para que el servidor (PHP) lea el consentimiento.
                var cookieValue = (prefs.analytics && prefs.marketing) ? 'full' : 'essential';
                var secureFlag = location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = 'ltms_cookie_consent=' + cookieValue + '; max-age=31536000; path=/; SameSite=Lax' + secureFlag;
                banner.classList.remove('visible');
                setTimeout(() => banner.remove(), 400);

                // Disparar eventos de consentimiento (GTM, fbq, gtag).
                window.dataLayer = window.dataLayer || [];
                dataLayer.push({ event: 'cookie_consent_update', consent: cookieValue });
                if (typeof fbq !== 'undefined') {
                    fbq('consent', cookieValue === 'full' ? 'grant' : 'revoke');
                }
                if (typeof gtag !== 'undefined') {
                    var g = cookieValue === 'full' ? 'granted' : 'denied';
                    gtag('consent', 'update', { ad_storage: g, analytics_storage: g, ad_user_data: g, ad_personalization: g });
                }

                // Disparar evento para que otros scripts reaccionen
                document.dispatchEvent(new CustomEvent('ltms:cookie-consent', { detail: prefs }));
            };

            banner.querySelector('.ltms-cookie-accept').addEventListener('click', () => {
                saveConsent({ essential: true, analytics: true, marketing: true });
            });

            banner.querySelector('.ltms-cookie-reject').addEventListener('click', () => {
                saveConsent({ essential: true, analytics: false, marketing: false });
            });

            banner.querySelector('.ltms-cookie-custom').addEventListener('click', () => {
                openCookiePreferences(saveConsent, banner);
            });
        }, 1500);
    }

    function openCookiePreferences(saveCallback, bannerEl) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">Preferencias de cookies</h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies esenciales</strong>
                                <p>Necesarias para el funcionamiento básico. No se pueden desactivar.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" checked disabled>
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies analíticas</strong>
                                <p>Nos ayudan a entender cómo usas el sitio para mejorarlo.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" id="ltms-cookie-analytics">
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies de marketing</strong>
                                <p>Usadas para mostrar anuncios personalizados.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" id="ltms-cookie-marketing">
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cookie-save">Guardar preferencias</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));

        overlay.querySelector('#ltms-cookie-save').addEventListener('click', () => {
            saveCallback({
                essential: true,
                analytics: overlay.querySelector('#ltms-cookie-analytics').checked,
                marketing: overlay.querySelector('#ltms-cookie-marketing').checked,
            });
            close();
        });
    }

    LTMS.UX.initCookieConsent = initCookieConsent;

    // ═══════════════════════════════════════════════════════════
    // 58. QUICK VIEW — Vista rápida de producto en modal
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal de vista rápida que permite ver un producto
     * sin abandonar la página actual. Incluye imagen,
     * precio, descripción breve y botón de añadir al carrito.
     */

    function openQuickView(productId, options = {}) {
        if (!productId) return;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-quick-view-overlay';

        overlay.innerHTML = `
            <div class="ltms-modal ltms-quick-view-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-qv-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-qv-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Vista rápida
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-quick-view-body">
                    <div class="ltms-quick-view-loading">
                        <div class="ltms-spinner-lg"></div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        // Fetch product data
        fetchQuickViewData(productId, overlay, close, options);
    }

    function fetchQuickViewData(productId, overlay, close, options) {
        // Try WooCommerce REST API or AJAX.
        // Task 67-B — Prefer the global ltmsUX bootstrap (available on every
        // page), fall back to ltmsDashboard (vendor dashboard context). Only
        // when neither is available do we fall back to fetching the product
        // page HTML, which is wasteful (≈ 300 KB per modal open).
        const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
            || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
        const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
            || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

        if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
            jQuery.post(ajaxUrl, {
                action: 'ltms_quick_view',
                nonce: ajaxNonce,
                product_id: productId,
            }, (response) => {
                if (response.success && response.data) {
                    renderQuickView(overlay, response.data, close, options);
                } else {
                    renderQuickViewError(overlay);
                }
            }).fail(() => renderQuickViewError(overlay));
        } else {
            // Fallback: fetch product page and extract data
            fetch('/?p=' + productId)
                .then((r) => r.text())
                .then((html) => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const name = doc.querySelector('.product_title')?.textContent || 'Producto';
                    const price = doc.querySelector('.price')?.textContent || '';
                    const img = doc.querySelector('.woocommerce-product-gallery__image img')?.src || '';
                    const desc = doc.querySelector('.woocommerce-product-details__short-description')?.textContent || '';
                    renderQuickView(overlay, { name, price, image: img, description: desc, url: '/?p=' + productId }, close, options);
                })
                .catch(() => renderQuickViewError(overlay));
        }
    }

    function renderQuickView(overlay, data, close, options) {
        const body = overlay.querySelector('.ltms-quick-view-body');
        body.innerHTML = `
            <div class="ltms-quick-view-grid">
                <div class="ltms-quick-view-image">
                    ${data.image ? `<img src="${escapeHtml(data.image)}" alt="${escapeHtml(data.name)}" data-lightbox>` : '<div class="ltms-quick-view-no-img">📦</div>'}
                </div>
                <div class="ltms-quick-view-info">
                    <h2 class="ltms-quick-view-name">${escapeHtml(data.name)}</h2>
                    <div class="ltms-quick-view-price">${escapeHtml(data.price)}</div>
                    ${data.description ? `<p class="ltms-quick-view-desc">${escapeHtml(data.description.substring(0, 200))}${data.description.length > 200 ? '...' : ''}</p>` : ''}
                    ${data.rating !== undefined ? `
                        <div class="ltms-quick-view-rating">
                            ${renderStars(data.rating)}
                            <span>${data.rating.toFixed(1)} (${data.review_count || 0})</span>
                        </div>
                    ` : ''}
                    <div class="ltms-quick-view-actions">
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-quick-view-add-cart" data-product-id="${data.id || ''}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Añadir al carrito
                        </button>
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-quick-view-wishlist" data-product-id="${data.id || ''}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            Favorito
                        </button>
                        <a href="${escapeHtml(data.url || '#')}" class="ltms-btn ltms-btn-outline">
                            Ver detalles
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        `;

        // Add to cart
        body.querySelector('.ltms-quick-view-add-cart').addEventListener('click', () => {
            if (typeof jQuery !== 'undefined') {
                jQuery.post(wc_add_to_cart_params ? wc_add_to_cart_params.ajax_url : '/wp-admin/admin-ajax.php', {
                    action: 'woocommerce_add_to_cart',
                    product_id: data.id || productId,
                    quantity: 1,
                }, (response) => {
                    if (response.fragments) {
                        jQuery.each(response.fragments, function(key, value) {
                            jQuery(key).replaceWith(value);
                        });
                    }
                    toast('success', 'Añadido al carrito', data.name);
                    // v2.9.208: redirect to /cart instead of opening drawer.
                    setTimeout(() => { window.location.href = (typeof ltmsUX !== 'undefined' && ltmsUX.cart_url) || '/cart/'; }, 800);
                    close();
                });
            } else {
                toast('success', 'Añadido', data.name);
                close();
            }
        });

        // Wishlist
        body.querySelector('.ltms-quick-view-wishlist').addEventListener('click', (e) => {
            toggleWishlist(data.id || productId, e.currentTarget);
        });

        // Track recently viewed
        trackRecentlyViewed(data.id || productId, data);
    }

    function renderQuickViewError(overlay) {
        const body = overlay.querySelector('.ltms-quick-view-body');
        body.innerHTML = '<div class="ltms-quick-view-error">No se pudo cargar el producto. Intenta de nuevo.</div>';
    }

    function renderStars(rating) {
        const full = Math.floor(rating);
        const half = rating % 1 >= 0.5;
        let html = '';
        for (let i = 0; i < 5; i++) {
            if (i < full) {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
            } else if (i === full && half) {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><defs><linearGradient id="half"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#half)"/></svg>';
            } else {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
            }
        }
        return `<div class="ltms-stars">${html}</div>`;
    }

    function initQuickView() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-quick-view]');
            if (!trigger) return;
            e.preventDefault();
            const productId = trigger.dataset.quickView || trigger.dataset.productId;
            if (productId) openQuickView(productId);
        });
    }

    LTMS.UX.openQuickView = openQuickView;

    // ═══════════════════════════════════════════════════════════
    // 59. WISHLIST — Favoritos persistente
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de favoritos/wishlist que persiste en localStorage.
     * Permite marcar productos como favoritos, ver la lista completa
     * y sincronizar entre dispositivos si hay backend.
     */

    function getWishlist() {
        try {
            return JSON.parse(localStorage.getItem('ltms-wishlist') || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveWishlist(items) {
        try {
            localStorage.setItem('ltms-wishlist', JSON.stringify(items));
        } catch (e) {}
        updateWishlistBadges(items.length);
    }

    function toggleWishlist(productId, btnEl) {
        if (!productId) return;
        const list = getWishlist();
        const index = list.indexOf(productId);

        if (index >= 0) {
            list.splice(index, 1);
            if (btnEl) {
                btnEl.classList.remove('active');
                btnEl.querySelector('svg')?.setAttribute('fill', 'none');
            }
            toast('info', 'Eliminado de favoritos', '');
        } else {
            list.push(productId);
            if (btnEl) {
                btnEl.classList.add('active');
                btnEl.querySelector('svg')?.setAttribute('fill', 'currentColor');
            }
            toast('success', 'Añadido a favoritos', '');
        }

        saveWishlist(list);
        announce(list.length + ' producto(s) en favoritos');
    }

    function isInWishlist(productId) {
        return getWishlist().includes(productId);
    }

    function updateWishlistBadges(count) {
        document.querySelectorAll('[data-wishlist-count]').forEach((el) => {
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        });
    }

    function initWishlist() {
        // Marcar botones de wishlist existentes como activos
        const wishlist = getWishlist();
        updateWishlistBadges(wishlist.length);

        // Hook en botones con data-wishlist
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-wishlist-toggle]');
            if (!btn) return;
            e.preventDefault();
            const productId = btn.dataset.wishlistToggle || btn.dataset.productId;
            toggleWishlist(productId, btn);
        });

        // Marcar botones activos
        document.querySelectorAll('[data-wishlist-toggle]').forEach((btn) => {
            const productId = btn.dataset.wishlistToggle || btn.dataset.productId;
            if (productId && isInWishlist(productId)) {
                btn.classList.add('active');
                btn.querySelector('svg')?.setAttribute('fill', 'currentColor');
            }
        });
    }

    LTMS.UX.toggleWishlist = toggleWishlist;
    LTMS.UX.getWishlist = getWishlist;
    LTMS.UX.isInWishlist = isInWishlist;

    // ═══════════════════════════════════════════════════════════
    // 60. RECENTLY VIEWED — Productos vistos recientemente
    // ═══════════════════════════════════════════════════════════

    /**
     * Rastrea productos vistos recientemente y muestra un widget
     * con los últimos 10 productos visitados.
     */

    function trackRecentlyViewed(productId, data) {
        if (!productId) return;

        let recent = [];
        try {
            recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
        } catch (e) {}

        // Remover si ya existe (mover al inicio)
        recent = recent.filter((item) => item.id !== productId);

        // Añadir al inicio
        recent.unshift({
            id: productId,
            name: data.name || '',
            price: data.price || '',
            image: data.image || '',
            url: data.url || '',
            timestamp: Date.now(),
        });

        // Mantener solo los últimos 10
        recent = recent.slice(0, 10);

        try {
            localStorage.setItem('ltms-recently-viewed', JSON.stringify(recent));
        } catch (e) {}

        renderRecentlyViewedWidget();
    }

    function renderRecentlyViewedWidget() {
        let recent = [];
        try {
            recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
        } catch (e) {}

        if (recent.length === 0) return;

        // Buscar o crear widget
        let widget = document.querySelector('.ltms-recently-viewed-widget');
        if (!widget) {
            // Solo mostrar en storefront, no en dashboard
            if (!document.querySelector('.ltms-storefront-page, .ltms-sellers-landing')) return;

            widget = document.createElement('div');
            widget.className = 'ltms-recently-viewed-widget ltms-card';
            widget.innerHTML = `
                <div class="ltms-card-header">
                    <div class="ltms-card-header-title">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Vistos recientemente
                        </h3>
                    </div>
                </div>
                <div class="ltms-card-body ltms-recently-viewed-body"></div>
            `;

            // Insertar después del contenido principal
            const main = document.querySelector('main, .ltms-storefront, #content');
            if (main) main.appendChild(widget);
        }

        const body = widget.querySelector('.ltms-recently-viewed-body');
        body.innerHTML = `
            <div class="ltms-recently-viewed-scroll">
                ${recent.map((item) => `
                    <a href="${escapeHtml(item.url || '#')}" class="ltms-recently-viewed-item" data-product-id="${item.id}">
                        <div class="ltms-recently-viewed-img">
                            ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '📦'}
                        </div>
                        <div class="ltms-recently-viewed-info">
                            <div class="ltms-recently-viewed-name">${escapeHtml(item.name)}</div>
                            <div class="ltms-recently-viewed-price">${escapeHtml(item.price)}</div>
                        </div>
                    </a>
                `).join('')}
            </div>
        `;

        // Click para quick view
        body.querySelectorAll('.ltms-recently-viewed-item').forEach((el) => {
            el.addEventListener('click', (e) => {
                // Si es click normal, dejar navegar; si tiene data-quick-view, abrir modal
            });
        });
    }

    function initRecentlyViewed() {
        // Renderizar widget si ya hay productos vistos
        setTimeout(renderRecentlyViewedWidget, 1000);

        // Tracking automático en páginas de producto
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:product: viewed', (e, data) => {
                if (data && data.id) trackRecentlyViewed(data.id, data);
            });
        }

        // Auto-detectar página de producto WooCommerce
        const productGallery = document.querySelector('.woocommerce-product-gallery');
        if (productGallery) {
            const productId = document.body.className.match(/postid-(\d+)/);
            if (productId) {
                const name = document.querySelector('.product_title')?.textContent || '';
                const price = document.querySelector('.price')?.textContent || '';
                const img = document.querySelector('.woocommerce-product-gallery__image img')?.src || '';
                trackRecentlyViewed(productId[1], { name, price, image: img, url: window.location.href });
            }
        }
    }

    LTMS.UX.trackRecentlyViewed = trackRecentlyViewed;

    // ═══════════════════════════════════════════════════════════
    // 62. SOCIAL SHARE — Botones de compartir
    // ═══════════════════════════════════════════════════════════

    /**
     * Botones de compartir en redes sociales con URL, título
     * e imagen del producto/página actual.
     */

    const SHARE_PLATFORMS = {
        whatsapp: {
            label: 'WhatsApp',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>',
            color: '#25D366',
            url: (data) => `https://wa.me/?text=${encodeURIComponent(data.title + ' ' + data.url)}`,
        },
        facebook: {
            label: 'Facebook',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            color: '#1877F2',
            url: (data) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(data.url)}`,
        },
        twitter: {
            label: 'X',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            color: '#000000',
            url: (data) => `https://twitter.com/intent/tweet?text=${encodeURIComponent(data.title)}&url=${encodeURIComponent(data.url)}`,
        },
        telegram: {
            label: 'Telegram',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.139-5.061 3.345-.48.329-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
            color: '#0088CC',
            url: (data) => `https://t.me/share/url?url=${encodeURIComponent(data.url)}&text=${encodeURIComponent(data.title)}`,
        },
        email: {
            label: 'Email',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            color: '#6B7280',
            url: (data) => `mailto:?subject=${encodeURIComponent(data.title)}&body=${encodeURIComponent(data.url)}`,
        },
        copy: {
            label: 'Copiar',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
            color: '#6B7280',
            url: null,
        },
    };

    function renderShareButtons(container, data = {}) {
        const shareData = {
            title: data.title || document.title,
            url: data.url || window.location.href,
            ...data,
        };

        const html = `
            <div class="ltms-social-share">
                <span class="ltms-share-label">Compartir:</span>
                <div class="ltms-share-buttons">
                    ${Object.entries(SHARE_PLATFORMS).map(([key, platform]) => `
                        <button type="button" class="ltms-share-btn ltms-share-${key}" data-share="${key}" aria-label="Compartir en ${escapeHtml(platform.label)}" title="${escapeHtml(platform.label)}" style="--share-color:${platform.color};">
                            ${platform.icon}
                        </button>
                    `).join('')}
                </div>
            </div>
        `;

        if (typeof container === 'string') {
            container = document.querySelector(container);
        }

        if (container) {
            container.innerHTML = html;

            container.querySelectorAll('[data-share]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const platform = btn.dataset.share;
                    const config = SHARE_PLATFORMS[platform];

                    if (platform === 'copy') {
                        navigator.clipboard.writeText(shareData.url).then(() => {
                            toast('success', 'Enlace copiado', 'Pégalo donde quieras compartirlo');
                        });
                        return;
                    }

                    if (config && config.url) {
                        const url = config.url(shareData);
                        window.open(url, '_blank', 'width=600,height=400,scrollbars=yes');
                    }
                });
            });
        }

        return html;
    }

    function initSocialShare() {
        // Auto-render en contenedores con data-share-buttons
        document.querySelectorAll('[data-share-buttons]').forEach((el) => {
            const data = {};
            if (el.dataset.shareTitle) data.title = el.dataset.shareTitle;
            if (el.dataset.shareUrl) data.url = el.dataset.shareUrl;
            renderShareButtons(el, data);
        });
    }

    LTMS.UX.renderShareButtons = renderShareButtons;

    // ═══════════════════════════════════════════════════════════
    // 64. PRODUCT COMPARISON — Comparación side-by-side
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite comparar hasta 4 productos lado a lado en una tabla
     * con sus características, precios y especificaciones.
     */

    function getCompareList() {
        try {
            return JSON.parse(localStorage.getItem('ltms-compare') || '[]');
        } catch (e) { return []; }
    }

    function toggleCompare(productId, data, btnEl) {
        if (!productId) return;
        const list = getCompareList();
        const index = list.findIndex((p) => p.id === productId);

        if (index >= 0) {
            list.splice(index, 1);
            if (btnEl) btnEl.classList.remove('active');
            toast('info', 'Eliminado de comparación', '');
        } else {
            if (list.length >= 4) {
                toast('warning', 'Máximo 4 productos', 'Elimina uno para comparar otro.');
                return;
            }
            list.push({ id: productId, ...data });
            if (btnEl) btnEl.classList.add('active');
            toast('success', 'Añadido a comparación', `${list.length}/4 productos seleccionados`);
        }

        try { localStorage.setItem('ltms-compare', JSON.stringify(list)); } catch (e) {}
        updateCompareBadge(list.length);
    }

    function updateCompareBadge(count) {
        document.querySelectorAll('[data-compare-count]').forEach((el) => {
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        });

        // Mostrar/ocultar barra flotante de comparación
        let bar = document.querySelector('.ltms-compare-bar');
        if (count > 0) {
            if (!bar) {
                bar = document.createElement('div');
                bar.className = 'ltms-compare-bar';
                document.body.appendChild(bar);
            }
            const list = getCompareList();
            bar.innerHTML = `
                <div class="ltms-compare-bar-items">
                    ${list.map((p) => `
                        <div class="ltms-compare-bar-item">
                            ${p.image ? `<img src="${escapeHtml(p.image)}" alt="">` : '<span>📦</span>'}
                            <button type="button" class="ltms-compare-bar-remove" data-compare-remove="${p.id}" aria-label="Quitar">×</button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-compare-now">
                    Comparar (${count})
                </button>
                <button type="button" class="ltms-compare-bar-clear" id="ltms-compare-clear">Limpiar</button>
            `;
            bar.classList.add('visible');

            bar.querySelectorAll('[data-compare-remove]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    toggleCompare(btn.dataset.compareRemove, {}, null);
                });
            });

            bar.querySelector('#ltms-compare-now').addEventListener('click', openCompareModal);
            bar.querySelector('#ltms-compare-clear').addEventListener('click', () => {
                try { localStorage.removeItem('ltms-compare'); } catch (e) {}
                updateCompareBadge(0);
                document.querySelectorAll('[data-compare-toggle].active').forEach((b) => b.classList.remove('active'));
                bar.classList.remove('visible');
            });
        } else if (bar) {
            bar.classList.remove('visible');
        }
    }

    function openCompareModal() {
        const list = getCompareList();
        if (list.length < 2) {
            toast('warning', 'Selecciona al menos 2 productos', 'Necesitas 2 o más para comparar.');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-compare-overlay';

        // Collect all unique attribute keys
        const allKeys = new Set(['price', 'stock', 'category', 'brand', 'sku', 'weight', 'dimensions', 'rating']);
        list.forEach((p) => { if (p.attributes) Object.keys(p.attributes).forEach((k) => allKeys.add(k)); });

        const attrLabels = {
            price: 'Precio', stock: 'Stock', category: 'Categoría', brand: 'Marca',
            sku: 'SKU', weight: 'Peso', dimensions: 'Dimensiones', rating: 'Calificación',
        };

        overlay.innerHTML = `
            <div class="ltms-modal ltms-compare-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="18"/><rect x="14" y="3" width="7" height="18"/></svg>
                        Comparar productos (${list.length})
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-compare-body">
                    <div class="ltms-compare-table-wrap">
                        <table class="ltms-compare-table">
                            <thead>
                                <tr>
                                    <th class="ltms-compare-spacer"></th>
                                    ${list.map((p) => `
                                        <th class="ltms-compare-product">
                                            <div class="ltms-compare-product-img">
                                                ${p.image ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}">` : '📦'}
                                            </div>
                                            <div class="ltms-compare-product-name">${escapeHtml(p.name || '')}</div>
                                            <a href="${escapeHtml(p.url || '#')}" class="ltms-btn ltms-btn-outline ltms-btn-sm">Ver</a>
                                        </th>
                                    `).join('')}
                                </tr>
                            </thead>
                            <tbody>
                                ${[...allKeys].map((key) => `
                                    <tr>
                                        <td class="ltms-compare-attr-label">${escapeHtml(attrLabels[key] || key)}</td>
                                        ${list.map((p) => {
                                            const val = p.attributes?.[key] ?? p[key] ?? '—';
                                            const isPrice = key === 'price';
                                            return `<td class="${isPrice ? 'ltms-compare-price' : ''}">${escapeHtml(String(val))}</td>`;
                                        }).join('')}
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    }

    function initCompare() {
        updateCompareBadge(getCompareList().length);

        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-compare-toggle]');
            if (!btn) return;
            e.preventDefault();
            const productId = btn.dataset.compareToggle || btn.dataset.productId;
            const data = {
                name: btn.dataset.compareName || '',
                price: btn.dataset.comparePrice || '',
                image: btn.dataset.compareImage || '',
                url: btn.dataset.compareUrl || '',
            };
            toggleCompare(productId, data, btn);
        });

        // Marcar botones activos
        const list = getCompareList();
        document.querySelectorAll('[data-compare-toggle]').forEach((btn) => {
            const pid = btn.dataset.compareToggle || btn.dataset.productId;
            if (pid && list.find((p) => p.id === pid)) btn.classList.add('active');
        });
    }

    LTMS.UX.toggleCompare = toggleCompare;
    LTMS.UX.getCompareList = getCompareList;

    // ═══════════════════════════════════════════════════════════
    // 66. PRICE RANGE SLIDER — Filtro de rango de precio
    // ═══════════════════════════════════════════════════════════

    /**
     * Slider dual para filtrar productos por rango de precio.
     * Sin librerías externas, usa dos input[type=range] superpuestos.
     */

    function createPriceRange(container, options = {}) {
        const min = options.min || 0;
        const max = options.max || 1000000;
        const step = options.step || 1000;
        const initialMin = options.initialMin ?? min;
        const initialMax = options.initialMax ?? max;
        const currency = options.currency || 'COP';
        const onChange = options.onChange;

        container.className = 'ltms-price-range';
        container.innerHTML = `
            <div class="ltms-price-range-track">
                <div class="ltms-price-range-fill" id="ltms-pr-fill"></div>
            </div>
            <input type="range" class="ltms-price-range-input ltms-price-range-min" min="${min}" max="${max}" step="${step}" value="${initialMin}" aria-label="Precio mínimo">
            <input type="range" class="ltms-price-range-input ltms-price-range-max" min="${min}" max="${max}" step="${step}" value="${initialMax}" aria-label="Precio máximo">
            <div class="ltms-price-range-values">
                <span class="ltms-price-range-min-val">${formatCurrency(initialMin, currency)}</span>
                <span class="ltms-price-range-sep">—</span>
                <span class="ltms-price-range-max-val">${formatCurrency(initialMax, currency)}</span>
            </div>
        `;

        const minInput = container.querySelector('.ltms-price-range-min');
        const maxInput = container.querySelector('.ltms-price-range-max');
        const fill = container.querySelector('#ltms-pr-fill');
        const minVal = container.querySelector('.ltms-price-range-min-val');
        const maxVal = container.querySelector('.ltms-price-range-max-val');

        function update() {
            let minV = parseInt(minInput.value, 10);
            let maxV = parseInt(maxInput.value, 10);

            // Prevenir cruce
            if (minV > maxV - step) {
                if (document.activeElement === minInput) {
                    minV = maxV - step;
                    minInput.value = minV;
                } else {
                    maxV = minV + step;
                    maxInput.value = maxV;
                }
            }

            const percentMin = ((minV - min) / (max - min)) * 100;
            const percentMax = ((maxV - min) / (max - min)) * 100;

            fill.style.left = percentMin + '%';
            fill.style.width = (percentMax - percentMin) + '%';

            minVal.textContent = formatCurrency(minV, currency);
            maxVal.textContent = formatCurrency(maxV, currency);

            if (onChange) onChange(minV, maxV);
        }

        minInput.addEventListener('input', update);
        maxInput.addEventListener('input', update);

        update();

        return {
            getValues: () => [parseInt(minInput.value, 10), parseInt(maxInput.value, 10)],
            setValues: (minV, maxV) => {
                minInput.value = minV;
                maxInput.value = maxV;
                update();
            },
        };
    }

    function initPriceRanges() {
        document.querySelectorAll('[data-price-range]').forEach((el) => {
            if (el.dataset.prInit) return;
            el.dataset.prInit = 'true';

            createPriceRange(el, {
                min: parseInt(el.dataset.min || '0', 10),
                max: parseInt(el.dataset.max || '1000000', 10),
                step: parseInt(el.dataset.step || '1000', 10),
                initialMin: parseInt(el.dataset.initialMin || el.dataset.min || '0', 10),
                initialMax: parseInt(el.dataset.initialMax || el.dataset.max || '1000000', 10),
                currency: el.dataset.currency || 'COP',
            });
        });
    }

    LTMS.UX.createPriceRange = createPriceRange;

    // ═══════════════════════════════════════════════════════════
    // 67. IMAGE ZOOM — Zoom de imagen al hover
    // ═══════════════════════════════════════════════════════════

    /**
     * Efecto de zoom en imágenes de producto al pasar el mouse.
     * Muestra una versión ampliada siguiendo el cursor.
     */

    function initImageZoom() {
        document.addEventListener('mousemove', (e) => {
            // v2.9.32: e.target puede ser un Document o text node que no tiene .closest()
            if (!e.target || typeof e.target.closest !== 'function') return;
            const container = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-zoom-container');
            if (!container) return;

            const img = container.querySelector('img');
            if (!img) return;

            const rect = container.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;

            img.style.transformOrigin = `${x}% ${y}%`;
            img.style.transform = 'scale(2)';
        });

        document.addEventListener('mouseleave', (e) => {
            const container = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-zoom-container');
            if (!container) return;

            const img = container.querySelector('img');
            if (img) {
                img.style.transform = 'scale(1)';
                img.style.transformOrigin = 'center';
            }
        }, true);

        // Auto-inicializar contenedores
        document.querySelectorAll('.ltms-zoom-container').forEach((container) => {
            if (container.dataset.zoomInit) return;
            container.dataset.zoomInit = 'true';
            container.style.cursor = 'zoom-in';

            const img = container.querySelector('img');
            if (img) {
                img.style.transition = 'transform 0.2s ease-out';
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 71. PRODUCT CAROUSEL — Slider de imágenes de producto
    // ═══════════════════════════════════════════════════════════

    /**
     * Carrusel de imágenes para productos con:
     * - Navegación por flechas y dots
     * - Swipe en móvil
     * - Zoom al click
     * - Thumbnail navigation
     */

    function initProductCarousels() {
        document.querySelectorAll('.ltms-carousel').forEach((carousel) => {
            if (carousel.dataset.carouselInit) return;
            carousel.dataset.carouselInit = 'true';

            const slides = carousel.querySelector('.ltms-carousel-slides');
            const dotsContainer = carousel.querySelector('.ltms-carousel-dots');
            const prevBtn = carousel.querySelector('.ltms-carousel-prev');
            const nextBtn = carousel.querySelector('.ltms-carousel-next');
            const thumbs = carousel.querySelectorAll('.ltms-carousel-thumb');

            if (!slides) return;

            const total = slides.children.length;
            let current = 0;

            // Crear dots si no existen
            if (dotsContainer && !dotsContainer.children.length) {
                for (let i = 0; i < total; i++) {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'ltms-carousel-dot' + (i === 0 ? ' active' : '');
                    dot.setAttribute('aria-label', `Ir a imagen ${i + 1}`);
                    dot.addEventListener('click', () => goTo(i));
                    dotsContainer.appendChild(dot);
                }
            }

            function goTo(index) {
                current = Math.max(0, Math.min(index, total - 1));
                slides.style.transform = `translateX(-${current * 100}%)`;

                carousel.querySelectorAll('.ltms-carousel-dot').forEach((d, i) => {
                    d.classList.toggle('active', i === current);
                });

                thumbs.forEach((t, i) => {
                    t.classList.toggle('active', i === current);
                });
            }

            function next() { goTo(current + 1 >= total ? 0 : current + 1); }
            function prev() { goTo(current - 1 < 0 ? total - 1 : current - 1); }

            if (prevBtn) prevBtn.addEventListener('click', prev);
            if (nextBtn) nextBtn.addEventListener('click', next);

            // Keyboard
            carousel.setAttribute('tabindex', '0');
            carousel.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
            });

            // Thumbnails
            thumbs.forEach((thumb, i) => {
                thumb.addEventListener('click', () => goTo(i));
            });

            // Touch / Swipe
            let touchStartX = 0;
            let touchEndX = 0;

            carousel.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX;
            }, { passive: true });

            carousel.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].clientX;
                const diff = touchStartX - touchEndX;

                if (Math.abs(diff) > 50) {
                    if (diff > 0) next();
                    else prev();
                }
            }, { passive: true });

            // Auto-play opcional
            if (carousel.dataset.autoplay === 'true') {
                const interval = parseInt(carousel.dataset.autoplayInterval || '5000', 10);
                let autoTimer = setInterval(next, interval);

                carousel.addEventListener('mouseenter', () => clearInterval(autoTimer));
                carousel.addEventListener('mouseleave', () => {
                    autoTimer = setInterval(next, interval);
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 72. QUANTITY STEPPER — Selector de cantidad
    // ═══════════════════════════════════════════════════════════

    /**
     * Componente reutilizable para seleccionar cantidad
     * con botones +/-, validación de min/max y soporte keyboard.
     */

    function createQuantityStepper(options = {}) {
        const min = options.min ?? 1;
        const max = options.max ?? 999;
        const step = options.step || 1;
        const initial = options.initial ?? min;
        const onChange = options.onChange;

        const container = document.createElement('div');
        container.className = 'ltms-qty-stepper';
        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Selector de cantidad');

        const decBtn = document.createElement('button');
        decBtn.type = 'button';
        decBtn.className = 'ltms-qty-btn ltms-qty-dec';
        decBtn.setAttribute('aria-label', 'Disminuir cantidad');
        decBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>';

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'ltms-qty-input';
        input.value = initial;
        input.min = min;
        input.max = max;
        input.step = step;
        input.setAttribute('aria-label', 'Cantidad');
        input.setAttribute('inputmode', 'numeric');

        const incBtn = document.createElement('button');
        incBtn.type = 'button';
        incBtn.className = 'ltms-qty-btn ltms-qty-inc';
        incBtn.setAttribute('aria-label', 'Aumentar cantidad');
        incBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

        container.appendChild(decBtn);
        container.appendChild(input);
        container.appendChild(incBtn);

        let current = initial;

        function setValue(val) {
            val = Math.max(min, Math.min(val, max));
            val = Math.round(val / step) * step;
            if (val === current) return;
            current = val;
            input.value = val;
            updateButtons();
            if (onChange) onChange(val);
        }

        function updateButtons() {
            decBtn.disabled = current <= min;
            incBtn.disabled = current >= max;
        }

        decBtn.addEventListener('click', () => setValue(current - step));
        incBtn.addEventListener('click', () => setValue(current + step));

        input.addEventListener('change', () => {
            const val = parseInt(input.value, 10);
            if (!isNaN(val)) setValue(val);
            else input.value = current;
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp') { e.preventDefault(); setValue(current + step); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); setValue(current - step); }
        });

        updateButtons();

        container.getValue = () => current;
        container.setValue = setValue;

        return container;
    }

    function initQuantitySteppers() {
        document.querySelectorAll('[data-qty-stepper]').forEach((el) => {
            if (el.dataset.qtyInit) return;
            el.dataset.qtyInit = 'true';

            const stepper = createQuantityStepper({
                min: parseInt(el.dataset.min || '1', 10),
                max: parseInt(el.dataset.max || '999', 10),
                step: parseInt(el.dataset.step || '1', 10),
                initial: parseInt(el.dataset.initial || el.dataset.min || '1', 10),
                onChange: (val) => {
                    const hidden = el.parentElement.querySelector(`input[name="${el.dataset.qtyName || 'quantity'}"]`);
                    if (hidden) hidden.value = val;
                    el.dispatchEvent(new CustomEvent('ltms:qty-change', { detail: { value: val }, bubbles: true }));
                },
            });

            el.innerHTML = '';
            el.appendChild(stepper);
        });
    }

    LTMS.UX.createQuantityStepper = createQuantityStepper;

    // ═══════════════════════════════════════════════════════════
    // 73. COUPON CODE — Input con validación visual
    // ═══════════════════════════════════════════════════════════

    /**
     * Campo de código de cupón con validación visual,
     * feedback inmediato y estado de carga.
     */

    function initCouponInputs() {
        document.querySelectorAll('[data-coupon-input]').forEach((wrapper) => {
            if (wrapper.dataset.couponInit) return;
            wrapper.dataset.couponInit = 'true';

            const input = wrapper.querySelector('input');
            const btn = wrapper.querySelector('button');
            if (!input || !btn) return;

            const validateUrl = wrapper.dataset.couponValidate;
            const nonceName = wrapper.dataset.couponNonce;

            btn.addEventListener('click', async () => {
                const code = input.value.trim().toUpperCase();
                if (!code) {
                    showCouponResult(wrapper, 'error', 'Ingresa un código');
                    return;
                }

                // Estado de carga
                btn.disabled = true;
                btn.innerHTML = '<span class="ltms-spinner"></span> Verificando...';
                wrapper.classList.remove('ltms-coupon-success', 'ltms-coupon-error');
                wrapper.classList.add('ltms-coupon-loading');

                try {
                    // UX-FAKE-6 FIX — Previously when no `data-coupon-validate`
                    // URL was provided, the code simulated a success toast
                    // claiming "¡Cupón aplicado! -10% de descuento" without
                    // ever sending the code to the server. The user was misled
                    // into believing a discount was applied. Now we always
                    // POST to the ltms_validate_coupon endpoint (registered in
                    // class-ltms-frontend-checkout-handler.php) via the global
                    // ltmsUX bootstrap and only show success when the server
                    // actually validates the coupon.
                    const ajaxUrl   = validateUrl
                        || ((typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) && ltmsUX.ajax_url)
                        || ((typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) && ltmsDashboard.ajax_url);
                    const ajaxNonce = (nonceName && window[nonceName])
                        || (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                        || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                    const restoreBtn = () => {
                        btn.disabled = false;
                        btn.textContent = 'Aplicar';
                        wrapper.classList.remove('ltms-coupon-loading');
                    };

                    if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                        const data = {
                            action: 'ltms_validate_coupon',
                            nonce: ajaxNonce,
                            coupon_code: code,
                        };

                        jQuery.post(ajaxUrl, data, (response) => {
                            if (response.success) {
                                showCouponResult(wrapper, 'success', response.data?.message || '¡Cupón aplicado!', response.data);
                            } else {
                                showCouponResult(wrapper, 'error', response.data?.message || response.data || 'Cupón no válido');
                            }
                            restoreBtn();
                        }).fail(() => {
                            showCouponResult(wrapper, 'error', 'Error de conexión. Intenta de nuevo.');
                            restoreBtn();
                        });
                    } else {
                        // UX-FAKE-6 FIX — do NOT fake success. Surface a real
                        // error so the user knows the coupon was not validated.
                        showCouponResult(wrapper, 'error', 'No se pudo validar el cupón en este momento. Recarga la página e inténtalo de nuevo.');
                        restoreBtn();
                    }
                } catch (e) {
                    showCouponResult(wrapper, 'error', 'Error inesperado');
                    btn.disabled = false;
                    btn.textContent = 'Aplicar';
                    wrapper.classList.remove('ltms-coupon-loading');
                }
            });

            // Enter key
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    btn.click();
                }
            });

            // Limpiar al escribir
            input.addEventListener('input', () => {
                wrapper.classList.remove('ltms-coupon-success', 'ltms-coupon-error');
                const msg = wrapper.querySelector('.ltms-coupon-message');
                if (msg) msg.remove();
            });
        });
    }

    function showCouponResult(wrapper, type, message, data) {
        wrapper.classList.remove('ltms-coupon-loading');
        wrapper.classList.add('ltms-coupon-' + type);

        let msg = wrapper.querySelector('.ltms-coupon-message');
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'ltms-coupon-message';
            wrapper.appendChild(msg);
        }

        const icon = type === 'success'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

        msg.innerHTML = `${icon} ${escapeHtml(message)}`;
        msg.className = 'ltms-coupon-message ltms-coupon-' + type;

        if (type === 'success') {
            toast('success', 'Cupón aplicado', message);
            if (data && data.discount) {
                announce(`Cupón aplicado. Descuento: ${data.discount}`);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 76. PRODUCT FILTER SIDEBAR — Filtros facetados
    // ═══════════════════════════════════════════════════════════

    /**
     * Sidebar de filtros con facets: categorías, atributos,
     * precio, rating. Aplica filtros en tiempo real con AJAX.
     */

    function initFilterSidebar() {
        const sidebar = document.querySelector('[data-filter-sidebar]');
        if (!sidebar) return;

        const form = sidebar.querySelector('form') || sidebar;
        const resultsContainer = document.querySelector(sidebar.dataset.filterTarget || '.ltms-products-grid, .ltms-filter-results');
        const ajaxUrl = sidebar.dataset.filterAjax;
        const state = { page: 1, filters: {} };

        // Recopilar filtros iniciales
        function collectFilters() {
            const data = {};
            // Checkboxes
            sidebar.querySelectorAll('input[type="checkbox"][data-filter]:checked').forEach((cb) => {
                const key = cb.dataset.filter;
                if (!data[key]) data[key] = [];
                data[key].push(cb.value);
            });
            // Radios
            sidebar.querySelectorAll('input[type="radio"][data-filter]:checked').forEach((r) => {
                data[r.dataset.filter] = r.value;
            });
            // Range inputs
            sidebar.querySelectorAll('[data-price-range]').forEach((el) => {
                const min = el.querySelector('.ltms-price-range-min');
                const max = el.querySelector('.ltms-price-range-max');
                if (min && max) {
                    data.price_min = min.value;
                    data.price_max = max.value;
                }
            });
            // Text search
            const search = sidebar.querySelector('[data-filter-search]');
            if (search) data.search = search.value;
            // Sort
            const sort = sidebar.querySelector('[data-filter-sort]');
            if (sort) data.sort = sort.value;

            return data;
        }

        function applyFilters(resetPage) {
            if (resetPage) state.page = 1;
            state.filters = collectFilters();

            // Update URL
            const params = new URLSearchParams();
            Object.entries(state.filters).forEach(([key, val]) => {
                if (Array.isArray(val)) val.forEach((v) => params.append(key + '[]', v));
                else params.set(key, val);
            });
            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState(null, '', newUrl);

            if (!ajaxUrl || !resultsContainer) {
                // Sin AJAX: aplicar filtros client-side
                applyClientSideFilters();
                return;
            }

            // AJAX filter
            resultsContainer.style.opacity = '0.5';
            resultsContainer.style.pointerEvents = 'none';

            const data = new FormData();
            data.append('action', 'ltms_filter_products');
            Object.entries(state.filters).forEach(([key, val]) => {
                if (Array.isArray(val)) val.forEach((v) => data.append(key + '[]', v));
                else data.append(key, val);
            });
            data.append('page', state.page);

            fetch(ajaxUrl, { method: 'POST', body: data })
                .then((r) => r.json())
                .then((response) => {
                    if (response.success && response.data) {
                        resultsContainer.innerHTML = response.data.html || '';
                        updateFilterCounts(response.data.counts || {});
                        updateActiveFiltersChips(state.filters);
                        announce(`${response.data.total || 0} productos encontrados`);
                    }
                })
                .catch(() => {
                    toast('error', 'Error', 'No se pudieron aplicar los filtros.');
                })
                .finally(() => {
                    resultsContainer.style.opacity = '';
                    resultsContainer.style.pointerEvents = '';
                });
        }

        function applyClientSideFilters() {
            const items = document.querySelectorAll('[data-product-item]');
            let visible = 0;

            items.forEach((item) => {
                let show = true;
                const categories = (item.dataset.categories || '').split(',');
                const price = parseFloat(item.dataset.price || '0');
                const rating = parseFloat(item.dataset.rating || '0');

                // Category filter
                if (state.filters.category && state.filters.category.length) {
                    show = show && state.filters.category.some((c) => categories.includes(c));
                }
                // Price filter
                if (state.filters.price_min && price < parseFloat(state.filters.price_min)) show = false;
                if (state.filters.price_max && price > parseFloat(state.filters.price_max)) show = false;
                // Rating filter
                if (state.filters.rating && rating < parseFloat(state.filters.rating)) show = false;
                // Search
                if (state.filters.search) {
                    const text = (item.textContent || '').toLowerCase();
                    show = show && text.includes(state.filters.search.toLowerCase());
                }

                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            updateActiveFiltersChips(state.filters);
            announce(`${visible} productos encontrados`);

            // Show empty state
            const emptyState = document.querySelector('[data-filter-empty]');
            if (emptyState) {
                emptyState.style.display = visible === 0 ? 'block' : 'none';
            }
        }

        function updateFilterCounts(counts) {
            Object.entries(counts).forEach(([key, val]) => {
                const el = sidebar.querySelector(`[data-filter-count="${key}"]`);
                if (el) el.textContent = `(${val})`;
            });
        }

        function updateActiveFiltersChips(filters) {
            let chipsContainer = document.querySelector('[data-filter-chips]');
            if (!chipsContainer) {
                chipsContainer = document.createElement('div');
                chipsContainer.className = 'ltms-filter-chips';
                chipsContainer.setAttribute('data-filter-chips', '');
                sidebar.parentNode.insertBefore(chipsContainer, sidebar.nextSibling);
            }

            const chips = [];
            Object.entries(filters).forEach(([key, val]) => {
                if (Array.isArray(val)) {
                    val.forEach((v) => {
                        chips.push({ key, value: v, label: v });
                    });
                } else if (val && key !== 'search' && key !== 'sort') {
                    chips.push({ key, value: val, label: val });
                }
            });

            if (!chips.length) {
                chipsContainer.innerHTML = '';
                chipsContainer.style.display = 'none';
                return;
            }

            chipsContainer.style.display = 'flex';
            chipsContainer.innerHTML = `
                <span class="ltms-filter-chips-label">Filtros activos:</span>
                ${chips.map((c) => `
                    <button type="button" class="ltms-filter-chip" data-filter-remove="${c.key}" data-filter-value="${escapeHtml(c.value)}">
                        ${escapeHtml(c.label)}
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                `).join('')}
                <button type="button" class="ltms-filter-clear-all">Limpiar todo</button>
            `;

            chipsContainer.querySelectorAll('[data-filter-remove]').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const key = chip.dataset.filterRemove;
                    const value = chip.dataset.filterValue;
                    if (key === 'price_min' || key === 'price_max') {
                        const input = sidebar.querySelector(`[data-price-range]`);
                        if (input) {
                            // Reset price range
                            applyFilters(true);
                        }
                    } else {
                        const cb = sidebar.querySelector(`input[data-filter="${key}"][value="${value}"]`);
                        if (cb) {
                            cb.checked = false;
                            cb.dispatchEvent(new Event('change'));
                        }
                    }
                });
            });

            chipsContainer.querySelector('.ltms-filter-clear-all')?.addEventListener('click', () => {
                sidebar.querySelectorAll('input[type="checkbox"][data-filter]:checked').forEach((cb) => cb.checked = false);
                sidebar.querySelectorAll('input[type="radio"][data-filter]:checked').forEach((r) => r.checked = false);
                applyFilters(true);
            });
        }

        // Bind events
        const debouncedApply = debounce(() => applyFilters(true), 300);

        sidebar.addEventListener('change', (e) => {
            if (e.target.matches('[data-filter]')) {
                applyFilters(true);
            }
        });

        sidebar.addEventListener('input', (e) => {
            if (e.target.matches('[data-filter-search]')) {
                debouncedApply();
            }
        });

        sidebar.addEventListener('change', (e) => {
            if (e.target.matches('[data-filter-sort]')) {
                applyFilters(false);
            }
        });

        // Mobile toggle
        const toggleBtn = document.querySelector('[data-filter-toggle]');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('ltms-filter-sidebar-open');
                document.body.style.overflow = sidebar.classList.contains('ltms-filter-sidebar-open') ? 'hidden' : '';
            });
        }

        // Close on overlay click
        const overlay = document.querySelector('[data-filter-overlay]');
        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('ltms-filter-sidebar-open');
                document.body.style.overflow = '';
            });
        }

        // Initial filter collection
        state.filters = collectFilters();
        updateActiveFiltersChips(state.filters);
    }

    // ═══════════════════════════════════════════════════════════
    // 77. MULTI-STEP CHECKOUT — Wizard de checkout
    // ═══════════════════════════════════════════════════════════

    /**
     * Wizard de checkout multi-paso con validación por paso,
     * indicador de progreso y persistencia de datos.
     */

    function initMultiStepCheckout() {
        const wizard = document.querySelector('[data-checkout-wizard]');
        if (!wizard) return;

        const steps = [...wizard.querySelectorAll('[data-checkout-step]')];
        const indicators = [...wizard.querySelectorAll('[data-step-indicator]')];
        let current = 0;

        function showStep(index) {
            steps.forEach((step, i) => {
                step.style.display = i === index ? 'block' : 'none';
            });

            indicators.forEach((ind, i) => {
                ind.classList.toggle('active', i === index);
                ind.classList.toggle('completed', i < index);
            });

            // Update buttons
            const prevBtn = wizard.querySelector('[data-checkout-prev]');
            const nextBtn = wizard.querySelector('[data-checkout-next]');
            const submitBtn = wizard.querySelector('[data-checkout-submit]');

            if (prevBtn) prevBtn.style.display = index > 0 ? '' : 'none';
            if (nextBtn) nextBtn.style.display = index < steps.length - 1 ? '' : 'none';
            if (submitBtn) submitBtn.style.display = index === steps.length - 1 ? '' : 'none';

            // Scroll to top
            wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Announce
            const stepTitle = steps[index]?.dataset.checkoutStepTitle || `Paso ${index + 1}`;
            announce(`Paso ${index + 1} de ${steps.length}: ${stepTitle}`);

            current = index;
        }

        function validateStep(index) {
            const step = steps[index];
            if (!step) return true;

            const required = step.querySelectorAll('[required]');
            let valid = true;
            let firstError = null;

            required.forEach((field) => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('ltms-input-error');
                    if (!firstError) firstError = field;
                } else {
                    field.classList.remove('ltms-input-error');
                }
            });

            if (!valid && firstError) {
                firstError.focus();
                toast('error', 'Campo requerido', 'Completa todos los campos obligatorios.');
            }

            return valid;
        }

        function nextStep() {
            if (!validateStep(current)) return;
            if (current < steps.length - 1) showStep(current + 1);
        }

        function prevStep() {
            if (current > 0) showStep(current - 1);
        }

        const nextBtn = wizard.querySelector('[data-checkout-next]');
        const prevBtn = wizard.querySelector('[data-checkout-prev]');
        const submitBtn = wizard.querySelector('[data-checkout-submit]');

        if (nextBtn) nextBtn.addEventListener('click', nextStep);
        if (prevBtn) prevBtn.addEventListener('click', prevStep);

        // Allow clicking on indicators to navigate (only to completed steps)
        indicators.forEach((ind, i) => {
            ind.addEventListener('click', () => {
                if (i < current || validateStep(current)) {
                    showStep(i);
                }
            });
        });

        // Persistence
        wizard.querySelectorAll('input, select, textarea').forEach((field) => {
            const key = 'ltms-checkout-' + (field.name || field.id);
            const saved = sessionStorage.getItem(key);
            if (saved && field.type !== 'password') {
                if (field.type === 'checkbox') field.checked = saved === 'true';
                else field.value = saved;
            }
            field.addEventListener('change', () => {
                sessionStorage.setItem(key, field.type === 'checkbox' ? field.checked : field.value);
            });
        });

        showStep(0);
    }

    // ═══════════════════════════════════════════════════════════
    // 78. ADDRESS AUTOCOMPLETE — Autocompletado de dirección
    // ═══════════════════════════════════════════════════════════

    /**
     * Autocompletado de direcciones usando la API de Google Places
     * (si está disponible) o fallback con sugerencias locales.
     */

    function initAddressAutocomplete() {
        document.querySelectorAll('[data-address-autocomplete]').forEach((input) => {
            if (input.dataset.acInit) return;
            input.dataset.acInit = 'true';

            const targetFields = {
                street: input.dataset.addressStreet,
                city: input.dataset.addressCity,
                state: input.dataset.addressState,
                zip: input.dataset.addressZip,
                country: input.dataset.addressCountry,
            };

            // Try Google Places
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                const autocomplete = new google.maps.places.Autocomplete(input, {
                    types: ['address'],
                    fields: ['address_components', 'formatted_address'],
                });

                autocomplete.addListener('place_changed', () => {
                    const place = autocomplete.getPlace();
                    if (!place.address_components) return;

                    const components = {};
                    place.address_components.forEach((c) => {
                        c.types.forEach((t) => { components[t] = c.long_name; });
                    });

                    if (targetFields.street) {
                        const el = document.querySelector(targetFields.street);
                        if (el) el.value = `${components.street_number || ''} ${components.route || ''}`.trim();
                    }
                    if (targetFields.city) {
                        const el = document.querySelector(targetFields.city);
                        if (el) el.value = components.locality || components.administrative_area_level_2 || '';
                    }
                    if (targetFields.state) {
                        const el = document.querySelector(targetFields.state);
                        if (el) el.value = components.administrative_area_level_1 || '';
                    }
                    if (targetFields.zip) {
                        const el = document.querySelector(targetFields.zip);
                        if (el) el.value = components.postal_code || '';
                    }
                    if (targetFields.country) {
                        const el = document.querySelector(targetFields.country);
                        if (el) el.value = components.country || '';
                    }

                    input.value = place.formatted_address || '';
                    input.dispatchEvent(new Event('ltms:address-selected', { bubbles: true, detail: place }));
                    toast('success', 'Dirección completada', 'Revisa los campos autocompletados.');
                });
                return;
            }

            // Fallback: simple suggestions dropdown
            let dropdown = null;
            let debounceTimer = null;

            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = input.value.trim();

                if (query.length < 3) {
                    if (dropdown) dropdown.remove();
                    return;
                }

                debounceTimer = setTimeout(() => {
                    // Simple local suggestions (can be replaced with API)
                    const suggestions = [
                        `Calle ${query}, Bogotá, Colombia`,
                        `Carrera ${query}, Medellín, Colombia`,
                        `Avenida ${query}, Cali, Colombia`,
                        `${query}, Ciudad de México, México`,
                    ];

                    if (dropdown) dropdown.remove();
                    dropdown = document.createElement('div');
                    dropdown.className = 'ltms-address-suggestions';
                    dropdown.innerHTML = suggestions.map((s) => `<div class="ltms-address-suggestion">${escapeHtml(s)}</div>`).join('');

                    input.parentNode.appendChild(dropdown);

                    dropdown.querySelectorAll('.ltms-address-suggestion').forEach((s) => {
                        s.addEventListener('click', () => {
                            input.value = s.textContent;
                            dropdown.remove();
                            dropdown = null;
                            input.dispatchEvent(new Event('ltms:address-selected', { bubbles: true }));
                        });
                    });
                }, 300);
            });

            input.addEventListener('blur', () => {
                setTimeout(() => { if (dropdown) dropdown.remove(); }, 200);
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 79. REVIEW SYSTEM — Sistema de reseñas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de reseñas con rating, comentarios, fotos
     * y respuestas del vendedor.
     */

    function initReviewSystem() {
        // Review form con star rating integrado
        document.querySelectorAll('[data-review-form]').forEach((form) => {
            if (form.dataset.reviewInit) return;
            form.dataset.reviewInit = 'true';

            const ratingContainer = form.querySelector('[data-review-rating]');
            if (ratingContainer) {
                const rating = createStarRating({
                    max: 5,
                    initial: 0,
                    onChange: (val) => {
                        const hidden = form.querySelector('input[name="rating"]');
                        if (hidden) hidden.value = val;

                        // Update label
                        const labels = ['','Pésimo','Malo','Regular','Bueno','Excelente'];
                        const labelEl = form.querySelector('[data-review-rating-label]');
                        if (labelEl) labelEl.textContent = labels[val] || '';
                    },
                });
                ratingContainer.innerHTML = '';
                ratingContainer.appendChild(rating);
            }

            // Photo upload
            const photoInput = form.querySelector('[data-review-photos]');
            if (photoInput) {
                photoInput.addEventListener('change', () => {
                    const preview = form.querySelector('[data-review-photo-preview]');
                    if (!preview) return;

                    preview.innerHTML = '';
                    [...photoInput.files].slice(0, 4).forEach((file) => {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const div = document.createElement('div');
                            div.className = 'ltms-review-photo-thumb';
                            div.innerHTML = `<img src="${e.target.result}" alt="">`;
                            preview.appendChild(div);
                        };
                        reader.readAsDataURL(file);
                    });
                });
            }

            // Submit
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const rating = form.querySelector('input[name="rating"]')?.value;
                if (!rating || rating === '0') {
                    toast('warning', 'Calificación requerida', 'Selecciona al menos 1 estrella.');
                    return;
                }

                const btn = form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';
                }

                // AJAX submit.
                // Task 67-B — Prefer the global ltmsUX bootstrap (available
                // everywhere), fall back to ltmsDashboard (vendor dashboard
                // context). Previously this gated on ltmsDashboard only and
                // silently failed on the customer-facing storefront.
                const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                    jQuery.post(ajaxUrl, {
                        action: 'ltms_submit_review',
                        nonce: ajaxNonce,
                        product_id: form.dataset.reviewForm,
                        rating: rating,
                        title: form.querySelector('[name="title"]')?.value || '',
                        comment: form.querySelector('[name="content"]')?.value || '',
                    }, (response) => {
                        if (response.success) {
                            toast('success', 'Reseña enviada', response.data?.message || '¡Gracias por tu calificación!');
                            form.reset();
                            form.querySelector('[data-review-photo-preview]')?.replaceChildren();
                            if (ratingContainer) {
                                ratingContainer.querySelector('.ltms-star-rating')?.dispatchEvent(new Event('reset'));
                            }
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            toast('error', 'Error', response.data?.message || response.data || 'No se pudo enviar la reseña.');
                        }
                    }).fail(() => {
                        toast('error', 'Error', 'No se pudo enviar. Intenta de nuevo.');
                    }).always(() => {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = 'Publicar reseña';
                        }
                    });
                } else {
                    // No AJAX bootstrap — surface a clear error instead of
                    // silently dropping the submission.
                    toast('error', 'No disponible', 'No se pudo enviar la reseña en este momento. Recarga la página e inténtalo de nuevo.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Publicar reseña';
                    }
                }
            });
        });

        // Helpful voting
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-review-helpful]');
            if (!btn) return;
            e.preventDefault();

            const reviewId = btn.dataset.reviewHelpful;
            const countEl = btn.querySelector('[data-helpful-count]');
            if (!countEl) return;

            const current = parseInt(countEl.textContent || '0', 10);
            countEl.textContent = current + 1;
            btn.classList.add('voted');
            btn.disabled = true;

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_review_helpful',
                    nonce: ltmsDashboard.nonce,
                    review_id: reviewId,
                });
            }

            toast('success', '¡Gracias!', 'Tu voto ayuda a otros compradores.');
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 80. ORDER SUCCESS — Animación de pedido exitoso
    // ═══════════════════════════════════════════════════════════

    /**
     * Animación de celebración cuando se completa un pedido:
     * check animado, confetti y resumen del pedido.
     */


    function initOrderSuccess() {
        // Auto-detect WooCommerce order received page
        if (document.body.classList.contains('woocommerce-order-received')) {
            const orderNumber = document.querySelector('.woocommerce-order-overview__order.order > strong')?.textContent;
            showOrderSuccess({
                order_number: orderNumber,
                message: '¡Gracias por tu compra!',
                continue_url: home_url || '/',
                track_url: window.location.href,
            });
        }

        // Manual trigger
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-order-success]');
            if (!trigger) return;
            e.preventDefault();
            showOrderSuccess({
                order_number: trigger.dataset.orderSuccess,
                continue_url: trigger.dataset.continueUrl,
                track_url: trigger.dataset.trackUrl,
            });
        });
    }


    // ═══════════════════════════════════════════════════════════
    // 81. BACKORDER — Notificación de pre-order/backorder
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra notificaciones cuando un producto está agotado
     * pero disponible para pre-order o backorder.
     */

    function initBackorderNotice() {
        document.querySelectorAll('[data-backorder]').forEach((el) => {
            if (el.dataset.boInit) return;
            el.dataset.boInit = 'true';

            const type = el.dataset.backorder; // 'pre-order' or 'backorder'
            const eta = el.dataset.backorderEta;
            const allowNotify = el.dataset.backorderNotify === 'true';

            const icon = type === 'pre-order'
                ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';

            el.className = 'ltms-backorder-notice ltms-backorder-' + type;
            el.innerHTML = `
                <div class="ltms-backorder-icon">${icon}</div>
                <div class="ltms-backorder-content">
                    <strong>${type === 'pre-order' ? 'Disponible en pre-order' : 'Producto en backorder'}</strong>
                    ${eta ? `<span>Fecha estimada de disponibilidad: <strong>${escapeHtml(eta)}</strong></span>` : ''}
                    ${type === 'pre-order' ? '<span class="ltms-backorder-hint">Reserva ahora y recíbelo cuando esté disponible.</span>' : '<span class="ltms-backorder-hint">Puedes comprarlo ahora, se enviará cuando tengamos stock.</span>'}
                </div>
                ${allowNotify ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-backorder-notify" data-backorder-notify="${el.dataset.productId || ''}">Avísame</button>` : ''}
            `;

            // Notify button
            const notifyBtn = el.querySelector('[data-backorder-notify]');
            if (notifyBtn) {
                notifyBtn.addEventListener('click', () => {
                    if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                        jQuery.post(ltmsDashboard.ajax_url, {
                            action: 'ltms_backorder_notify',
                            nonce: ltmsDashboard.nonce,
                            product_id: notifyBtn.dataset.backorderNotify,
                        }, (response) => {
                            if (response.success) {
                                toast('success', 'Notificación activada', 'Te avisaremos cuando el producto esté disponible.');
                                notifyBtn.disabled = true;
                                notifyBtn.textContent = '✓ Notificación activa';
                            }
                        });
                    } else {
                        toast('success', 'Notificación activada', 'Te avisaremos cuando esté disponible.');
                        notifyBtn.disabled = true;
                        notifyBtn.textContent = '✓ Activado';
                    }
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 82. STICKY ADD-TO-CART — Barra fija para móvil
    // ═══════════════════════════════════════════════════════════

    /**
     * Barra fija en el bottom de la pantalla que aparece cuando
     * el botón de añadir al carrito sale del viewport.
     * Muestra precio, nombre y botón de compra rápida.
     */

    let stickyBar = null;
    let stickyBarVisible = false;

    function initStickyAddToCart() {
        // Solo en páginas de producto
        const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
        if (!addToCartBtn) return;

        // Crear barra
        stickyBar = document.createElement('div');
        stickyBar.className = 'ltms-sticky-addcart';
        stickyBar.innerHTML = `
            <div class="ltms-sticky-addcart-info">
                <div class="ltms-sticky-addcart-img" id="ltms-sticky-img"></div>
                <div class="ltms-sticky-addcart-text">
                    <div class="ltms-sticky-addcart-name" id="ltms-sticky-name"></div>
                    <div class="ltms-sticky-addcart-price" id="ltms-sticky-price"></div>
                </div>
            </div>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-sticky-addcart-btn" id="ltms-sticky-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                Añadir al carrito
            </button>
        `;
        document.body.appendChild(stickyBar);

        // Llenar datos del producto
        const productImg = document.querySelector('.woocommerce-product-gallery__image img, .product-image img');
        const productName = document.querySelector('.product_title, .product-name, h1');
        const productPrice = document.querySelector('.price, .product-price, .woocommerce-Price-amount');

        if (productImg) stickyBar.querySelector('#ltms-sticky-img').style.backgroundImage = `url(${productImg.src})`;
        if (productName) stickyBar.querySelector('#ltms-sticky-name').textContent = productName.textContent.trim();
        if (productPrice) stickyBar.querySelector('#ltms-sticky-price').textContent = productPrice.textContent.trim();

        // Botón: disparar el botón real de añadir al carrito
        stickyBar.querySelector('#ltms-sticky-btn').addEventListener('click', () => {
            addToCartBtn.click();
            toast('success', 'Añadido al carrito', '');
        });

        // Observer para detectar cuando el botón sale del viewport
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const shouldShow = !entry.isIntersecting && entry.boundingClientRect.top < 0;

                if (shouldShow && !stickyBarVisible) {
                    stickyBar.classList.add('visible');
                    stickyBarVisible = true;
                    // Ajustar padding del body para que el bottom nav no solape
                    if (window.innerWidth <= 768) {
                        document.body.style.paddingBottom = '140px';
                    }
                } else if (!shouldShow && stickyBarVisible) {
                    stickyBar.classList.remove('visible');
                    stickyBarVisible = false;
                    document.body.style.paddingBottom = '';
                }
            });
        }, { threshold: 0 });

        observer.observe(addToCartBtn);
    }

    // ═══════════════════════════════════════════════════════════
    // 83. PRODUCT TABS — Pestañas de producto
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de pestañas para páginas de producto:
     * Descripción, Especificaciones, Reseñas, Envío, etc.
     */

    function initProductTabs() {
        document.querySelectorAll('[data-product-tabs]').forEach((container) => {
            if (container.dataset.tabsInit) return;
            container.dataset.tabsInit = 'true';

            const triggers = container.querySelectorAll('[data-tab-trigger]');
            const panels = container.querySelectorAll('[data-tab-panel]');

            triggers.forEach((trigger) => {
                trigger.addEventListener('click', () => {
                    const target = trigger.dataset.tabTrigger;

                    // Update triggers
                    triggers.forEach((t) => {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    trigger.classList.add('active');
                    trigger.setAttribute('aria-selected', 'true');

                    // Update panels
                    panels.forEach((panel) => {
                        const isActive = panel.dataset.tabPanel === target;
                        panel.classList.toggle('active', isActive);
                        panel.style.display = isActive ? 'block' : 'none';
                    });

                    announce(`Pestaña: ${trigger.textContent.trim()}`);
                });
            });

            // Keyboard navigation
            triggers.forEach((trigger, i) => {
                trigger.addEventListener('keydown', (e) => {
                    let newIndex = i;
                    if (e.key === 'ArrowRight') { e.preventDefault(); newIndex = (i + 1) % triggers.length; }
                    else if (e.key === 'ArrowLeft') { e.preventDefault(); newIndex = (i - 1 + triggers.length) % triggers.length; }
                    else if (e.key === 'Home') { e.preventDefault(); newIndex = 0; }
                    else if (e.key === 'End') { e.preventDefault(); newIndex = triggers.length - 1; }

                    if (newIndex !== i) {
                        triggers[newIndex].focus();
                        triggers[newIndex].click();
                    }
                });
            });

            // Activar primera pestaña por defecto si no hay ninguna activa
            if (!container.querySelector('[data-tab-trigger].active') && triggers.length) {
                triggers[0].click();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 84. VARIANT SELECTOR — Selector de variantes
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de variantes de producto (color, talla, material)
     * con feedback visual, disponibilidad y sincronización de precio.
     */

    function initVariantSelector() {
        document.querySelectorAll('[data-variant-group]').forEach((group) => {
            if (group.dataset.variantInit) return;
            group.dataset.variantInit = 'true';

            const variantName = group.dataset.variantGroup; // ej: "color", "size"
            const options = group.querySelectorAll('[data-variant-option]');
            const labelEl = group.querySelector('[data-variant-label]');
            const priceEl = document.querySelector(group.dataset.variantPriceTarget || '[data-variant-price]');
            const stockEl = document.querySelector(group.dataset.variantStockTarget || '[data-variant-stock]');

            options.forEach((option) => {
                option.addEventListener('click', () => {
                    if (option.classList.contains('disabled')) return;

                    // Deselect all
                    options.forEach((o) => {
                        o.classList.remove('selected');
                        o.setAttribute('aria-pressed', 'false');
                    });

                    // Select this
                    option.classList.add('selected');
                    option.setAttribute('aria-pressed', 'true');

                    // Update label
                    if (labelEl) {
                        labelEl.textContent = option.dataset.variantLabel || option.textContent.trim();
                    }

                    // Update price
                    if (priceEl && option.dataset.variantPrice) {
                        priceEl.textContent = option.dataset.variantPrice;
                    }

                    // Update stock
                    if (stockEl && option.dataset.variantStock !== undefined) {
                        const stock = parseInt(option.dataset.variantStock, 10);
                        stockEl.innerHTML = renderStockIndicator(stock, {
                            threshold: 5,
                            maxStock: 50,
                        });
                    }

                    // Update hidden input
                    const hidden = document.querySelector(`input[name="${variantName}"]`);
                    if (hidden) hidden.value = option.dataset.variantValue || option.textContent.trim();

                    // Update add-to-cart button state
                    const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
                    if (addToCartBtn && option.dataset.variantDisabled === 'true') {
                        addToCartBtn.disabled = true;
                        addToCartBtn.textContent = 'No disponible';
                    } else if (addToCartBtn) {
                        addToCartBtn.disabled = false;
                        addToCartBtn.innerHTML = 'Añadir al carrito';
                    }

                    // Dispatch event
                    group.dispatchEvent(new CustomEvent('ltms:variant-selected', {
                        detail: {
                            name: variantName,
                            value: option.dataset.variantValue || option.textContent.trim(),
                            label: option.dataset.variantLabel || option.textContent.trim(),
                            price: option.dataset.variantPrice,
                            stock: option.dataset.variantStock,
                        },
                        bubbles: true,
                    }));
                });
            });
        });

        // Auto-check all variant groups are selected before add to cart
        const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', (e) => {
                const groups = document.querySelectorAll('[data-variant-group][data-variant-required="true"]');
                let allSelected = true;

                groups.forEach((group) => {
                    if (!group.querySelector('[data-variant-option].selected')) {
                        allSelected = false;
                        group.classList.add('ltms-variant-error');
                        const label = group.dataset.variantGroup;
                        toast('warning', 'Selecciona una opción', `Por favor selecciona ${label}.`);
                    } else {
                        group.classList.remove('ltms-variant-error');
                    }
                });

                if (!allSelected) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }, true);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 85. TRUST BADGES — Insignian de confianza
    // ═══════════════════════════════════════════════════════════

    /**
     * Componente de badges de confianza: pago seguro, envío gratis,
     * garantía, devoluciones, etc.
     */

    const TRUST_BADGES = {
        secure_payment: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            label: 'Pago seguro',
            desc: 'Tus datos están protegidos',
            color: '#16A34A',
        },
        free_shipping: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
            label: 'Envío gratis',
            desc: 'En compras superiores a $100.000',
            color: '#3282B8',
        },
        warranty: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 5-3.5 7.5-8.5 9.5C7.5 19.5 4 17 4 12V6l8-3 8 3v6z"/></svg>',
            label: 'Garantía',
            desc: '30 días de garantía',
            color: '#F39C12',
        },
        returns: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
            label: 'Devoluciones',
            desc: '15 días para devoluciones',
            color: '#8B5CF6',
        },
        support: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
            label: 'Soporte 24/7',
            desc: 'Atención al cliente siempre',
            color: '#EC4899',
        },
        authentic: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            label: '100% Auténtico',
            desc: 'Productos originales garantizados',
            color: '#16A34A',
        },
    };

    function renderTrustBadges(container, badges = []) {
        if (typeof container === 'string') container = document.querySelector(container);
        if (!container) return;

        const badgeKeys = badges.length ? badges : Object.keys(TRUST_BADGES);

        container.className = 'ltms-trust-badges';
        container.innerHTML = badgeKeys.map((key) => {
            const badge = TRUST_BADGES[key];
            if (!badge) return '';
            return `
                <div class="ltms-trust-badge" style="--badge-color:${badge.color};">
                    <div class="ltms-trust-badge-icon">${badge.icon}</div>
                    <div class="ltms-trust-badge-content">
                        <strong>${escapeHtml(badge.label)}</strong>
                        <span>${escapeHtml(badge.desc)}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function initTrustBadges() {
        document.querySelectorAll('[data-trust-badges]').forEach((el) => {
            if (el.dataset.tbInit) return;
            el.dataset.tbInit = 'true';

            const badges = el.dataset.trustBadges ? el.dataset.trustBadges.split(',') : [];
            renderTrustBadges(el, badges);
        });
    }

    LTMS.UX.renderTrustBadges = renderTrustBadges;

    // ═══════════════════════════════════════════════════════════
    // 86. DELIVERY DATE PICKER — Selector de fecha de entrega
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de fecha de entrega con:
     * - Días no laborables excluidos
     * - Fecha mínima (envío en X días)
     * - Slots horarios opcionales
     */

    function initDeliveryDatePickers() {
        document.querySelectorAll('[data-delivery-picker]').forEach((input) => {
            if (input.dataset.ddpInit) return;
            input.dataset.ddpInit = 'true';

            const minDays = parseInt(input.dataset.minDays || '2', 10);
            const excludeWeekends = input.dataset.excludeWeekends === 'true';
            const excludedDates = (input.dataset.excludedDates || '').split(',').filter(Boolean);
            const slotContainer = document.querySelector(input.dataset.slotTarget);

            // Set min date
            const minDate = new Date();
            minDate.setDate(minDate.getDate() + minDays);
            input.min = minDate.toISOString().split('T')[0];

            // Validate date on change
            input.addEventListener('change', () => {
                const selected = new Date(input.value);
                const day = selected.getDay();

                let error = null;

                // Check weekend
                if (excludeWeekends && (day === 0 || day === 6)) {
                    error = 'No hacemos entregas en fin de semana.';
                }

                // Check excluded dates
                const dateStr = selected.toISOString().split('T')[0];
                if (excludedDates.includes(dateStr)) {
                    error = 'No hay entregas disponibles en esta fecha.';
                }

                // Check past dates
                if (selected < minDate) {
                    error = `La fecha mínima de entrega es ${minDate.toLocaleDateString('es-CO')}.`;
                }

                if (error) {
                    toast('warning', 'Fecha no válida', error);
                    input.value = '';
                    if (slotContainer) slotContainer.innerHTML = '';
                    return;
                }

                // Load time slots
                if (slotContainer) {
                    loadTimeSlots(slotContainer, input.value);
                }

                // Dispatch event
                input.dispatchEvent(new CustomEvent('ltms:delivery-date-selected', {
                    detail: { date: input.value },
                    bubbles: true,
                }));
            });
        });
    }

    function loadTimeSlots(container, date) {
        const slots = [
            { value: '09:00-12:00', label: 'Mañana (9:00 - 12:00)' },
            { value: '12:00-15:00', label: 'Mediodía (12:00 - 15:00)' },
            { value: '15:00-18:00', label: 'Tarde (15:00 - 18:00)' },
            { value: '18:00-20:00', label: 'Noche (18:00 - 20:00)' },
        ];

        container.innerHTML = `
            <div class="ltms-delivery-slots">
                <label class="ltms-delivery-slots-title">Horario de entrega:</label>
                <div class="ltms-delivery-slots-grid">
                    ${slots.map((slot, i) => `
                        <label class="ltms-delivery-slot">
                            <input type="radio" name="delivery_slot" value="${escapeHtml(slot.value)}" ${i === 0 ? 'checked' : ''}>
                            <span>${escapeHtml(slot.label)}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
    }

    // ═══════════════════════════════════════════════════════════
    // 87. GIFT WRAPPING — Opción de envoltura de regalo
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite añadir envoltura de regalo al pedido con mensaje
     * personalizado y vista previa.
     */

    function initGiftWrapping() {
        document.querySelectorAll('[data-gift-wrapping]').forEach((container) => {
            if (container.dataset.gwInit) return;
            container.dataset.gwInit = 'true';

            const price = container.dataset.giftWrappingPrice || '$5.000';
            const wrapper = document.createElement('div');
            wrapper.className = 'ltms-gift-wrapping';
            wrapper.innerHTML = `
                <label class="ltms-gift-wrapping-toggle">
                    <input type="checkbox" id="ltms-gift-wrapping-check">
                    <span class="ltms-gift-wrapping-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                    </span>
                    <div class="ltms-gift-wrapping-info">
                        <strong>Envoltura de regalo</strong>
                        <span>+${escapeHtml(price)} · Mensaje personalizado incluido</span>
                    </div>
                </label>
                <div class="ltms-gift-wrapping-options" style="display:none;">
                    <div class="ltms-gift-wrapping-preview">
                        <div class="ltms-gift-preview-box">
                            <div class="ltms-gift-preview-ribbon"></div>
                        </div>
                    </div>
                    <textarea class="ltms-gift-wrapping-message" placeholder="Mensaje para la tarjeta de regalo (máx. 200 caracteres)" maxlength="200" rows="3"></textarea>
                    <div class="ltms-gift-wrapping-counter"><span>0</span>/200</div>
                </div>
            `;
            container.appendChild(wrapper);

            const check = wrapper.querySelector('#ltms-gift-wrapping-check');
            const options = wrapper.querySelector('.ltms-gift-wrapping-options');
            const message = wrapper.querySelector('.ltms-gift-wrapping-message');
            const counter = wrapper.querySelector('.ltms-gift-wrapping-counter span');

            check.addEventListener('change', () => {
                options.style.display = check.checked ? 'block' : 'none';

                // UX-FAKE-5 FIX — Persist the gift-wrapping choice in a hidden
                // input named `ltms_gift_wrapping` so WooCommerce's checkout
                // POST includes it and the `woocommerce_cart_calculate_fees`
                // handler (registered in class-ltms-frontend-checkout-handler.php)
                // can read it via `$_POST['ltms_gift_wrapping']`. Without this,
                // the checkbox visually toggled but no fee was ever applied.
                // The hidden input is created on the fly if the page does not
                // already provide one so the value survives navigation.
                let hidden = document.querySelector('input[name="ltms_gift_wrapping"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'ltms_gift_wrapping';
                    // Append to the WC checkout form when present, otherwise to body.
                    const checkoutForm = document.querySelector('form.checkout, form.woocommerce-checkout, #ltms-checkout-form');
                    (checkoutForm || document.body).appendChild(hidden);
                }
                hidden.value = check.checked ? 'yes' : 'no';

                // Dispatch event
                container.dispatchEvent(new CustomEvent('ltms:gift-wrapping-change', {
                    detail: { enabled: check.checked },
                    bubbles: true,
                }));

                if (check.checked) {
                    toast('success', 'Envoltura de regalo añadida', `+${price}`);
                    // Trigger WC checkout review refresh so the new fee
                    // appears in the order totals immediately. On non-checkout
                    // pages this is a no-op (no listener bound).
                    if (typeof jQuery !== 'undefined') {
                        try { jQuery(document.body).trigger('update_checkout'); } catch (e) {}
                    }
                } else {
                    if (typeof jQuery !== 'undefined') {
                        try { jQuery(document.body).trigger('update_checkout'); } catch (e) {}
                    }
                }
            });

            message.addEventListener('input', () => {
                counter.textContent = message.value.length;
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 88. ABANDONED CART — Recuperación de carrito abandonado
    // ═══════════════════════════════════════════════════════════

    /**
     * Detecta cuando el usuario va a abandonar la página con items
     * en el carrito y muestra un modal de recuperación con incentivo.
     */

    let cartRecoveryShown = false;

    function initAbandonedCartRecovery() {
        // v2.9.46: DESACTIVADO completamente — el modal es demasiado intrusivo
        // y se dispara en momentos inapropiados (al añadir al carrito, al mover
        // el mouse, etc.). Se reactivará solo cuando se configure correctamente
        // con un delay mínimo de 60s y solo en intenciones reales de salida.
        return;

        /* CÓDIGO ORIGINAL DESACTIVADO:
        if (document.body.classList.contains('woocommerce-checkout')) return;
        if (document.body.classList.contains('woocommerce-order-received')) return;
        if (document.body.classList.contains('woocommerce-cart')) return;
        if (document.querySelector('.ltms-auth-container')) return;
        if (cartDrawerState.drawer) return;
        */

        // Detectar mouseleave hacia la parte superior (intención de salir)
        document.addEventListener('mouseleave', (e) => {
            if (e.clientY <= 0 && !cartRecoveryShown) {
                checkCartAndShowRecovery();
            }
        });

        // También detectar en mobile: visibilitychange
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !cartRecoveryShown) {
                // Guardar timestamp para mostrar al volver
                try { sessionStorage.setItem('ltms-cart-abandon-time', Date.now().toString()); } catch (e) {}
            } else if (!document.hidden && !cartRecoveryShown) {
                const abandonTime = sessionStorage.getItem('ltms-cart-abandon-time');
                if (abandonTime) {
                    const elapsed = Date.now() - parseInt(abandonTime, 10);
                    if (elapsed > 30000) { // >30s away
                        sessionStorage.removeItem('ltms-cart-abandon-time');
                        checkCartAndShowRecovery();
                    }
                }
            }
        });
    }

    function checkCartAndShowRecovery() {
        // Check if cart has items via WooCommerce
        if (typeof jQuery === 'undefined') return;

        const cartCountEl = document.querySelector('.ltms-sf-cart-count, .cart-count');
        const cartCount = cartCountEl ? parseInt(cartCountEl.textContent || '0', 10) : 0;

        if (cartCount === 0) {
            // Try WC fragments
            if (typeof wc_cart_fragments_params !== 'undefined') {
                jQuery.get(wc_cart_fragments_params.wc_ajax_url.replace('%%endpoint%%', 'get_refreshed_fragments'), (response) => {
                    if (response.fragments && response.cart_hash && response.cart_hash !== '') {
                        showCartRecoveryModal();
                    }
                });
            }
            return;
        }

        showCartRecoveryModal();
    }

    function showCartRecoveryModal() {
        cartRecoveryShown = true;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-cart-recovery-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-cart-recovery-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-cr-title">
                <div class="ltms-cart-recovery-header">
                    <div class="ltms-cart-recovery-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    </div>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-cart-recovery-body">
                    <h3 class="ltms-cart-recovery-title" id="ltms-cr-title">¡Espera! Tienes items en tu carrito</h3>
                    <p class="ltms-cart-recovery-msg">No te vayas sin completar tu compra. Tienes productos esperándote.</p>
                    <div class="ltms-cart-recovery-incentive">
                        <div class="ltms-cart-recovery-incentive-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>
                        </div>
                        <div>
                            <strong>¡Oferta especial para ti!</strong>
                            <span>Usa el código <code>QUEDATE10</code> para 10% de descuento</span>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer ltms-cart-recovery-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Seguir navegando</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cr-continue">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        Ir al carrito
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        overlay.querySelector('#ltms-cr-continue').addEventListener('click', () => {
            close();
            // v2.9.208: redirect to /cart instead of opening drawer.
            window.location.href = (typeof ltmsUX !== 'undefined' && ltmsUX.cart_url) || '/cart/';
        });

        announce('Tienes productos en tu carrito. ¿Deseas completar tu compra?');
    }

    // ═══════════════════════════════════════════════════════════
    // 89. PRODUCT RECOMMENDATIONS — "También te puede gustar"
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget de productos recomendados basado en:
     * - Productos vistos recientemente
     * - Categoría del producto actual
     * - Comprados juntos
     */

    function initProductRecommendations() {
        document.querySelectorAll('[data-recommendations]').forEach((container) => {
            if (container.dataset.recInit) return;
            container.dataset.recInit = 'true';

            const type = container.dataset.recommendations; // 'related', 'viewed', 'cross-sell'
            const limit = parseInt(container.dataset.recLimit || '6', 10);
            // Task 67-B — Prefer the server-provided URL, fall back to the
            // global ltmsUX bootstrap, then to ltmsDashboard for back-compat.
            const ajaxUrl   = container.dataset.recAjax
                || (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_get_recommendations',
                    nonce: ajaxNonce,
                    type: type,
                    limit: limit,
                    product_id: container.dataset.productId || '',
                }, (response) => {
                    if (response.success && response.data && response.data.products) {
                        renderRecommendations(container, response.data.products, type);
                    }
                });
            } else {
                // Fallback: usar recently viewed
                const recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
                if (recent.length) {
                    renderRecommendations(container, recent.slice(0, limit).map((r) => ({
                        id: r.id,
                        name: r.name,
                        price: r.price,
                        image: r.image,
                        url: r.url,
                    })), 'viewed');
                }
            }
        });
    }

    function renderRecommendations(container, products, type) {
        if (!products || !products.length) return;

        const titles = {
            'related': 'Productos relacionados',
            'viewed': 'Vistos recientemente',
            'cross-sell': 'También te puede gustar',
            'up-sell': 'Mejora tu experiencia',
        };

        const title = titles[type] || 'Recomendados para ti';

        container.className = 'ltms-recommendations';
        container.innerHTML = `
            <div class="ltms-recommendations-header">
                <h3>${escapeHtml(title)}</h3>
            </div>
            <div class="ltms-recommendations-scroll">
                ${products.map((p) => `
                    <a href="${escapeHtml(p.url || '#')}" class="ltms-recommendation-card" data-product-id="${p.id || ''}" data-quick-view="${p.id || ''}">
                        <div class="ltms-recommendation-img">
                            ${p.image ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}" loading="lazy">` : '📦'}
                        </div>
                        <div class="ltms-recommendation-info">
                            <div class="ltms-recommendation-name">${escapeHtml(p.name || '')}</div>
                            <div class="ltms-recommendation-price">${escapeHtml(p.price || '')}</div>
                        </div>
                    </a>
                `).join('')}
            </div>
        `;
    }

    // ═══════════════════════════════════════════════════════════
    // 90. SEARCH AUTOCOMPLETE — Búsqueda con sugerencias
    // ═══════════════════════════════════════════════════════════

    /**
     * Autocompletado de búsqueda con sugerencias en tiempo real:
     * productos, categorías, búsquedas populares.
     */

    function initSearchAutocomplete() {
        document.querySelectorAll('[data-search-autocomplete]').forEach((input) => {
            if (input.dataset.sacInit) return;
            input.dataset.sacInit = 'true';

            const ajaxUrl = input.dataset.searchAutocomplete
                || (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);
            const minChars = parseInt(input.dataset.minChars || '2', 10);
            let dropdown = null;
            let timer = null;
            let currentRequest = null;

            input.setAttribute('autocomplete', 'off');

            function createDropdown() {
                if (dropdown) dropdown.remove();
                dropdown = document.createElement('div');
                dropdown.className = 'ltms-search-autocomplete';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dropdown);
            }

            function showLoading() {
                createDropdown();
                dropdown.innerHTML = '<div class="ltms-sac-loading"><div class="ltms-spinner-lg"></div></div>';
            }

            function showResults(data) {
                createDropdown();

                if (!data || (!data.products?.length && !data.categories?.length && !data.popular?.length)) {
                    dropdown.innerHTML = '<div class="ltms-sac-empty">Sin resultados. Intenta con otros términos.</div>';
                    return;
                }

                let html = '';

                if (data.products?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Productos</div>
                            ${data.products.slice(0, 5).map((p) => `
                                <a href="${escapeHtml(p.url || '#')}" class="ltms-sac-item ltms-sac-product">
                                    <div class="ltms-sac-item-img">${p.image ? `<img src="${escapeHtml(p.image)}" alt="" loading="lazy">` : '📦'}</div>
                                    <div class="ltms-sac-item-info">
                                        <div class="ltms-sac-item-name">${escapeHtml(p.name)}</div>
                                        <div class="ltms-sac-item-price">${escapeHtml(p.price || '')}</div>
                                    </div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                if (data.categories?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Categorías</div>
                            ${data.categories.slice(0, 3).map((c) => `
                                <a href="${escapeHtml(c.url || '#')}" class="ltms-sac-item ltms-sac-category">
                                    <div class="ltms-sac-item-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                    </div>
                                    <div class="ltms-sac-item-name">${escapeHtml(c.name)}</div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                if (data.popular?.length && !data.products?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Búsquedas populares</div>
                            ${data.popular.slice(0, 5).map((term) => `
                                <a href="#" class="ltms-sac-item ltms-sac-popular" data-sac-term="${escapeHtml(term)}">
                                    <div class="ltms-sac-item-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    </div>
                                    <div class="ltms-sac-item-name">${escapeHtml(term)}</div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                html += `
                    <div class="ltms-sac-footer">
                        <kbd>Enter</kbd> para ver todos los resultados
                    </div>
                `;

                dropdown.innerHTML = html;

                // Click en búsqueda popular
                dropdown.querySelectorAll('[data-sac-term]').forEach((el) => {
                    el.addEventListener('click', (e) => {
                        e.preventDefault();
                        input.value = el.dataset.sacTerm;
                        input.form?.submit();
                    });
                });
            }

            input.addEventListener('input', () => {
                clearTimeout(timer);
                const query = input.value.trim();

                if (query.length < minChars) {
                    if (dropdown) dropdown.remove();
                    return;
                }

                timer = setTimeout(() => {
                    showLoading();

                    if (currentRequest) currentRequest.abort();

                    if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                        currentRequest = jQuery.post(ajaxUrl, {
                            action: 'ltms_search_autocomplete',
                            nonce: ajaxNonce,
                            query: query,
                        }, (response) => {
                            if (response.success) {
                                showResults(response.data);
                            } else {
                                showResults({});
                            }
                        }).fail(() => showResults({}));
                    } else {
                        // No AJAX bootstrap available — surface a clear
                        // empty state instead of pretending to search.
                        setTimeout(() => showResults({}), 300);
                    }
                }, 250);
            });

            input.addEventListener('focus', () => {
                if (input.value.trim().length >= minChars && !dropdown) {
                    input.dispatchEvent(new Event('input'));
                }
            });

            // Close on outside click
            document.addEventListener('click', (e) => {
                if (dropdown && !input.parentNode.contains(e.target)) {
                    dropdown.remove();
                    dropdown = null;
                }
            });

            // Keyboard navigation
            let selectedIndex = -1;
            input.addEventListener('keydown', (e) => {
                if (!dropdown) return;

                const items = dropdown.querySelectorAll('.ltms-sac-item');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateSelection(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateSelection(items);
                } else if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault();
                    items[selectedIndex]?.click();
                } else if (e.key === 'Escape') {
                    dropdown.remove();
                    dropdown = null;
                }
            });

            function updateSelection(items) {
                items.forEach((item, i) => {
                    item.classList.toggle('active', i === selectedIndex);
                });
                items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 91. MULTI-CURRENCY — Switcher de moneda
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite cambiar entre COP, MXN y USD con conversión
     * automática de precios en la página.
     */

    const CURRENCY_RATES = {
        COP: { symbol: '$', decimals: 0, locale: 'es-CO' },
        MXN: { symbol: '$', decimals: 2, locale: 'es-MX' },
        USD: { symbol: 'US$', decimals: 2, locale: 'en-US' },
    };

    // Tasa base: COP
    const EXCHANGE_RATES = {
        COP: 1,
        MXN: 0.0045,
        USD: 0.00025,
    };

    function getCurrentCurrency() {
        try { return localStorage.getItem('ltms-currency') || 'COP'; }
        catch (e) { return 'COP'; }
    }

    function setCurrency(currency) {
        if (!CURRENCY_RATES[currency]) return;
        try { localStorage.setItem('ltms-currency', currency); } catch (e) {}
        convertAllPrices(currency);
        updateCurrencySwitchers(currency);
        announce(`Moneda cambiada a ${currency}`);
        toast('success', 'Moneda actualizada', `Precios en ${currency}`);
    }

    function convertPrice(priceCop, targetCurrency) {
        const rate = EXCHANGE_RATES[targetCurrency] || 1;
        const converted = priceCop * rate;
        const config = CURRENCY_RATES[targetCurrency] || CURRENCY_RATES.COP;

        return config.symbol + new Intl.NumberFormat(config.locale, {
            minimumFractionDigits: config.decimals,
            maximumFractionDigits: config.decimals,
        }).format(converted);
    }

    function convertAllPrices(currency) {
        document.querySelectorAll('[data-price-cop]').forEach((el) => {
            const cop = parseFloat(el.dataset.priceCop);
            if (!isNaN(cop)) {
                el.textContent = convertPrice(cop, currency);
            }
        });
    }

    function updateCurrencySwitchers(currency) {
        document.querySelectorAll('[data-currency-switcher]').forEach((switcher) => {
            switcher.querySelectorAll('[data-currency-option]').forEach((opt) => {
                opt.classList.toggle('active', opt.dataset.currencyOption === currency);
            });
        });
    }

    function initMultiCurrency() {
        // Render switchers
        document.querySelectorAll('[data-currency-switcher]').forEach((switcher) => {
            if (switcher.dataset.csInit) return;
            switcher.dataset.csInit = 'true';

            const currencies = (switcher.dataset.currencyOptions || 'COP,MXN,USD').split(',');
            switcher.innerHTML = currencies.map((cur) => {
                const config = CURRENCY_RATES[cur];
                if (!config) return '';
                return `<button type="button" class="ltms-currency-option" data-currency-option="${cur}">${cur}</button>`;
            }).join('');

            switcher.querySelectorAll('[data-currency-option]').forEach((opt) => {
                opt.addEventListener('click', () => setCurrency(opt.dataset.currencyOption));
            });
        });

        // Initial conversion
        const current = getCurrentCurrency();
        if (current !== 'COP') {
            convertAllPrices(current);
        }
        updateCurrencySwitchers(current);
    }

    LTMS.UX.setCurrency = setCurrency;
    LTMS.UX.getCurrentCurrency = getCurrentCurrency;
    LTMS.UX.convertPrice = convertPrice;

    // ═══════════════════════════════════════════════════════════
    // 94. SOCIAL PROOF — Notificaciones de compras recientes
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra notificaciones flotantes de compras recientes:
     * "Juan desde Bogotá compró Camiseta Azul hace 5 min"
     * Genera urgencia y confianza social.
     *
     * Task 67-A / UX-SOCIAL-1 FIX: This module previously fabricated
     * notifications with `Math.random()` picking from hardcoded arrays of
     * names, cities and products. That violates Colombia's Estatuto del
     * Consumidor (Ley 1480/2011 art. 31 — información engañosa) and Mexico's
     * LFPU. The module is now DISABLED until a real `ltms_get_recent_purchases`
     * AJAX endpoint is implemented server-side that returns anonymized recent
     * completed orders (first-name only + city + real product image).
     *
     * TODO: implement ltms_get_recent_purchases endpoint in
     * includes/frontend/class-ltms-frontend-checkout-handler.php (or a new
     * class-ltms-frontend-social-proof.php) querying completed WC orders in
     * the last hour with first-name + city anonymization. Gate behind explicit
     * admin opt-in (ltms_social_proof_enabled option). When ready, uncomment
     * the fetch() block below and remove the early return.
     */

    const SOCIAL_PROOF_NAMES = ['Juan', 'María', 'Carlos', 'Ana', 'Pedro', 'Laura', 'Diego', 'Sofía', 'Andrés', 'Valeria', 'Camilo', 'Daniela', 'Felipe', 'Isabella', 'Sebastián', 'Camila'];
    const SOCIAL_PROOF_CITIES = ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Cúcuta', 'Bucaramanga', 'Pereira', 'Santa Marta', 'Ibagué', 'Manizales', 'Villavicencio'];
    const SOCIAL_PROOF_PRODUCTS = [
        { name: 'Camiseta Premium', img: '👕' },
        { name: 'Zapatillas Deportivas', img: '👟' },
        { name: 'Reloj Inteligente', img: '⌚' },
        { name: 'Auriculares Bluetooth', img: '🎧' },
        { name: 'Mochila Urbana', img: '🎒' },
        { name: 'Gafas de Sol', img: '🕶️' },
        { name: 'Perfume Importado', img: '🧴' },
        { name: 'Set de Cocina', img: '🍳' },
    ];

    let socialProofActive = false;
    let socialProofTimer = null;

    function initSocialProof() {
        // UX-SOCIAL-1: Module disabled — fabricated data violates consumer
        // protection law. Re-enable only when ltms_get_recent_purchases
        // endpoint exists and returns real anonymized purchases.
        //
        // To re-enable once the endpoint is implemented:
        //   1. Replace the early-return below with a fetch() call to
        //     ltmsUX.ajax_url + '?action=ltms_get_recent_purchases&nonce=' + ltmsUX.nonce
        //   2. Cache the response and call showSocialProofNotification(purchase)
        //     with a real purchase object on each cycle.
        //   3. Hide the module silently if the fetch fails or returns no data.
        return;

        /* eslint-disable no-unreachable */
        // The code below is preserved for reference once the endpoint exists.
        // It will not run while the early return above is in place.

        // No mostrar en login, registro o dashboard admin
        if (document.querySelector('.ltms-auth-container, .ltms-dashboard-container')) return;
        if (document.body.classList.contains('wp-admin')) return;

        // No mostrar si el usuario ya las cerró
        try {
            if (sessionStorage.getItem('ltms-social-proof-dismissed') === 'true') return;
        } catch (e) {}

        // Esperar 15s antes de la primera notificación
        setTimeout(() => {
            socialProofActive = true;
            showSocialProofNotification();
        }, 15000);
        /* eslint-enable no-unreachable */
    }

    function showSocialProofNotification() {
        if (!socialProofActive) return;

        // UX-SOCIAL-1: Do NOT fabricate names/cities/products with Math.random().
        // The arrays above are kept only for the future implementation that will
        // fetch real purchases from the server. Returning early ensures no
        // fabricated notification is shown to the user.
        return;

        /* eslint-disable no-unreachable */
        const name = SOCIAL_PROOF_NAMES[Math.floor(Math.random() * SOCIAL_PROOF_NAMES.length)];
        const city = SOCIAL_PROOF_CITIES[Math.floor(Math.random() * SOCIAL_PROOF_CITIES.length)];
        const product = SOCIAL_PROOF_PRODUCTS[Math.floor(Math.random() * SOCIAL_PROOF_PRODUCTS.length)];
        const minutesAgo = Math.floor(Math.random() * 30) + 1;

        const notif = document.createElement('div');
        notif.className = 'ltms-social-proof';
        notif.innerHTML = `
            <div class="ltms-social-proof-img">${product.img}</div>
            <div class="ltms-social-proof-content">
                <div class="ltms-social-proof-text">
                    <strong>${escapeHtml(name)}</strong> desde ${escapeHtml(city)} compró
                    <strong>${escapeHtml(product.name)}</strong>
                </div>
                <div class="ltms-social-proof-time">Hace ${minutesAgo} min · ✓ Verificado</div>
            </div>
            <button type="button" class="ltms-social-proof-close" aria-label="Cerrar">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        `;

        document.body.appendChild(notif);
        requestAnimationFrame(() => notif.classList.add('visible'));

        // Auto-hide after 5s
        const hideTimer = setTimeout(() => hideSocialProof(notif), 5000);

        // Close button
        notif.querySelector('.ltms-social-proof-close').addEventListener('click', () => {
            clearTimeout(hideTimer);
            hideSocialProof(notif);
            try { sessionStorage.setItem('ltms-social-proof-dismissed', 'true'); } catch (e) {}
            socialProofActive = false;
        });

        // Hover pauses auto-hide
        notif.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        notif.addEventListener('mouseleave', () => {
            setTimeout(() => hideSocialProof(notif), 3000);
        });

        // Schedule next notification (30-90s random)
        socialProofTimer = setTimeout(() => {
            if (socialProofActive) showSocialProofNotification();
        }, 30000 + Math.random() * 60000);
        /* eslint-enable no-unreachable */
    }

    function hideSocialProof(notif) {
        if (!notif || !notif.parentNode) return;
        notif.classList.remove('visible');
        setTimeout(() => {
            if (notif.parentNode) notif.parentNode.removeChild(notif);
        }, 400);
    }

    // ═══════════════════════════════════════════════════════════
    // 95. SIZE GUIDE — Modal de guía de tallas
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal con tabla de guía de tallas y conversión
     * internacional (Colombia, México, USA, EU).
     */

    function initSizeGuide() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-size-guide]');
            if (!trigger) return;
            e.preventDefault();

            const guideData = trigger.dataset.sizeGuide;
            const guideType = trigger.dataset.sizeGuideType || 'clothing';

            openSizeGuideModal(guideType, guideData);
        });
    }

    function openSizeGuideModal(type, customData) {
        const tables = {
            clothing: {
                title: 'Guía de tallas — Ropa',
                headers: ['Talla', 'Busto (cm)', 'Cintura (cm)', 'Cadera (cm)'],
                rows: [
                    ['XS', '78-82', '60-64', '86-90'],
                    ['S', '82-86', '64-68', '90-94'],
                    ['M', '86-90', '68-72', '94-98'],
                    ['L', '90-94', '72-76', '98-102'],
                    ['XL', '94-98', '76-80', '102-106'],
                    ['XXL', '98-102', '80-84', '106-110'],
                ],
            },
            shoes: {
                title: 'Guía de tallas — Calzado',
                headers: ['CO', 'MX', 'USA', 'EU', 'Largo pie (cm)'],
                rows: [
                    ['34', '3.5', '4', '35', '22.5'],
                    ['35', '4', '5', '36', '23'],
                    ['36', '5', '6', '37', '23.5'],
                    ['37', '6', '7', '38', '24'],
                    ['38', '7', '8', '39', '25'],
                    ['39', '8', '9', '40', '25.5'],
                    ['40', '9', '10', '41', '26'],
                    ['41', '10', '11', '42', '27'],
                ],
            },
            rings: {
                title: 'Guía de tallas — Anillos',
                headers: ['Talla', 'Diámetro (mm)', 'Perímetro (mm)'],
                rows: [
                    ['6', '16.5', '52'],
                    ['7', '17.3', '54.4'],
                    ['8', '18.2', '57'],
                    ['9', '19.0', '59.5'],
                    ['10', '19.8', '62.1'],
                    ['11', '20.6', '64.6'],
                    ['12', '21.4', '67.2'],
                ],
            },
        };

        const guide = tables[type] || tables.clothing;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-size-guide-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-sg-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-sg-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-4 4 4 5-5"/></svg>
                        ${escapeHtml(guide.title)}
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-size-guide-body">
                    <div class="ltms-size-guide-tip">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <span>Mide tu cuerpo con una cinta métrica y compara con la tabla. Si estás entre dos tallas, elige la más grande.</span>
                    </div>
                    <table class="ltms-size-guide-table">
                        <thead>
                            <tr>${guide.headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr>
                        </thead>
                        <tbody>
                            ${guide.rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-modal-close">Entendido</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    }

    LTMS.UX.openSizeGuide = openSizeGuideModal;

    // ═══════════════════════════════════════════════════════════
    // 96. PRODUCT VIDEO — Soporte de video en galería
    // ═══════════════════════════════════════════════════════════

    /**
     * Añade soporte para videos de producto (YouTube/Vimeo/MP4)
     * dentro del carrusel de imágenes existente.
     */

    function initProductVideo() {
        document.querySelectorAll('[data-product-video]').forEach((videoTrigger) => {
            if (videoTrigger.dataset.pvInit) return;
            videoTrigger.dataset.pvInit = 'true';

            videoTrigger.addEventListener('click', (e) => {
                e.preventDefault();
                const videoUrl = videoTrigger.dataset.productVideo;
                const videoType = videoTrigger.dataset.videoType || 'youtube';

                openVideoModal(videoUrl, videoType);
            });
        });
    }

    function openVideoModal(url, type) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-video-modal-overlay';

        let embedHtml = '';
        if (type === 'youtube') {
            const videoId = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\n?#]+)/)?.[1] || url;
            embedHtml = `<iframe src="https://www.youtube.com/embed/${escapeHtml(videoId)}?autoplay=1&rel=0" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
        } else if (type === 'vimeo') {
            const videoId = url.match(/vimeo\.com\/(\d+)/)?.[1] || url;
            embedHtml = `<iframe src="https://player.vimeo.com/video/${escapeHtml(videoId)}?autoplay=1" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>`;
        } else {
            embedHtml = `<video src="${escapeHtml(url)}" controls autoplay playsinline></video>`;
        }

        overlay.innerHTML = `
            <div class="ltms-video-modal" role="dialog" aria-modal="true" aria-label="Video del producto">
                <button type="button" class="ltms-video-modal-close" aria-label="Cerrar video">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="ltms-video-modal-content">
                    ${embedHtml}
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => overlay.classList.add('visible'));

        const close = () => {
            overlay.classList.remove('visible');
            document.body.style.overflow = '';
            setTimeout(() => {
                if (overlay.parentNode) overlay.remove();
            }, 300);
        };

        overlay.querySelector('.ltms-video-modal-close').addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape') {
                close();
                document.removeEventListener('keydown', escHandler);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 97. RECENT SEARCHES — Términos de búsqueda recientes
    // ═══════════════════════════════════════════════════════════

    /**
     * Guarda y muestra búsquedas recientes del usuario
     * en los campos de búsqueda.
     */

    function getRecentSearches() {
        try {
            return JSON.parse(localStorage.getItem('ltms-recent-searches') || '[]');
        } catch (e) { return []; }
    }

    function addRecentSearch(term) {
        if (!term || term.trim().length < 2) return;
        let searches = getRecentSearches();

        // Remover duplicados
        searches = searches.filter((s) => s.toLowerCase() !== term.toLowerCase());

        // Añadir al inicio
        searches.unshift(term.trim());

        // Mantener solo 8
        searches = searches.slice(0, 8);

        try { localStorage.setItem('ltms-recent-searches', JSON.stringify(searches)); } catch (e) {}
    }

    function clearRecentSearches() {
        try { localStorage.removeItem('ltms-recent-searches'); } catch (e) {}
    }

    function initRecentSearches() {
        const searchInputs = document.querySelectorAll('[data-search-autocomplete], [data-recent-searches], input[type="search"]');

        searchInputs.forEach((input) => {
            if (input.dataset.rsInit) return;
            input.dataset.rsInit = 'true';

            let dropdown = null;

            input.addEventListener('focus', () => {
                const recent = getRecentSearches();
                if (!recent.length) return;
                if (input.value.trim().length > 0) return; // No mostrar si ya está escribiendo

                if (dropdown) dropdown.remove();
                dropdown = document.createElement('div');
                dropdown.className = 'ltms-recent-searches-dropdown';
                dropdown.innerHTML = `
                    <div class="ltms-recent-searches-header">
                        <span>Búsquedas recientes</span>
                        <button type="button" class="ltms-recent-searches-clear">Limpiar</button>
                    </div>
                    ${recent.map((term) => `
                        <div class="ltms-recent-search-item" data-term="${escapeHtml(term)}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>${escapeHtml(term)}</span>
                        </div>
                    `).join('')}
                `;

                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dropdown);

                dropdown.querySelectorAll('.ltms-recent-search-item').forEach((item) => {
                    item.addEventListener('click', () => {
                        input.value = item.dataset.term;
                        dropdown.remove();
                        dropdown = null;
                        input.form?.submit();
                    });
                });

                dropdown.querySelector('.ltms-recent-searches-clear')?.addEventListener('click', () => {
                    clearRecentSearches();
                    dropdown.remove();
                    dropdown = null;
                });
            });

            // Save search on submit
            input.form?.addEventListener('submit', () => {
                addRecentSearch(input.value);
            });

            // Save on Enter
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    addRecentSearch(input.value);
                }
            });

            // Close on blur
            input.addEventListener('blur', () => {
                setTimeout(() => {
                    if (dropdown) {
                        dropdown.remove();
                        dropdown = null;
                    }
                }, 200);
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 102. MULTI-VENDOR CART SPLIT — Carrito agrupado por vendedor
    // ═══════════════════════════════════════════════════════════

    /**
     * En un marketplace multi-vendor, muestra los items del carrito
     * agrupados por vendedor con subtotales independientes.
     */

    function initMultiVendorCart() {
        const cartContainer = document.querySelector('[data-multivendor-cart]');
        if (!cartContainer) return;
        if (cartContainer.dataset.mvcInit) return;
        cartContainer.dataset.mvcInit = 'true';

        // Group items by vendor
        const items = [...cartContainer.querySelectorAll('[data-cart-item-vendor]')];
        const vendorGroups = {};

        items.forEach((item) => {
            const vendor = item.dataset.cartItemVendor;
            const vendorName = item.dataset.cartVendorName || vendor;
            if (!vendorGroups[vendor]) {
                vendorGroups[vendor] = { name: vendorName, items: [], subtotal: 0 };
            }
            vendorGroups[vendor].items.push(item);
            const price = parseFloat(item.dataset.cartItemPrice || '0');
            const qty = parseInt(item.dataset.cartItemQty || '1', 10);
            vendorGroups[vendor].subtotal += price * qty;
        });

        // Restructure cart display
        Object.entries(vendorGroups).forEach(([vendorId, group]) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'ltms-vendor-cart-group';
            wrapper.innerHTML = `
                <div class="ltms-vendor-cart-header">
                    <div class="ltms-vendor-cart-info">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <span>Vendido por <strong>${escapeHtml(group.name)}</strong></span>
                    </div>
                    <span class="ltms-vendor-cart-subtotal">${formatCurrency(group.subtotal)}</span>
                </div>
                <div class="ltms-vendor-cart-items"></div>
            `;

            // Move items into group
            const itemsContainer = wrapper.querySelector('.ltms-vendor-cart-items');
            group.items.forEach((item) => {
                itemsContainer.appendChild(item.cloneNode(true));
                item.style.display = 'none';
            });

            cartContainer.appendChild(wrapper);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 103. ESTIMATED DELIVERY — Calculadora de fecha de entrega
    // ═══════════════════════════════════════════════════════════

    /**
     * Calcula y muestra la fecha estimada de entrega basada en
     * la ubicación del usuario y el método de envío.
     */

    function initEstimatedDelivery() {
        document.querySelectorAll('[data-estimated-delivery]').forEach((container) => {
            if (container.dataset.edInit) return;
            container.dataset.edInit = 'true';

            const baseDays = parseInt(container.dataset.baseDays || '3', 10);
            const city = container.dataset.city || '';
            const shippingMethod = container.dataset.shippingMethod || 'standard';

            // City-based additional days
            const cityDelays = { 'Bogotá': 0, 'Medellín': 1, 'Cali': 2, 'Barranquilla': 3, 'Cartagena': 3 };
            const extraDays = cityDelays[city] || 4;

            // Method adjustments
            const methodMultipliers = { standard: 1, express: 0.4, same_day: 0.1, pickup: 0 };
            const multiplier = methodMultipliers[shippingMethod] || 1;

            const totalDays = Math.ceil(baseDays * multiplier) + extraDays;

            const deliveryDate = new Date();
            deliveryDate.setDate(deliveryDate.getDate() + totalDays);

            // Skip weekends
            while (deliveryDate.getDay() === 0 || deliveryDate.getDay() === 6) {
                deliveryDate.setDate(deliveryDate.getDate() + 1);
            }

            const dateStr = deliveryDate.toLocaleDateString('es-CO', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
            });

            const isExpress = shippingMethod === 'express' || shippingMethod === 'same_day';

            container.className = 'ltms-estimated-delivery' + (isExpress ? ' ltms-delivery-express' : '');
            container.innerHTML = `
                <div class="ltms-delivery-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="ltms-delivery-content">
                    <strong>Entrega estimada:</strong>
                    <span>${escapeHtml(dateStr.charAt(0).toUpperCase() + dateStr.slice(1))}</span>
                </div>
            `;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 104. SUBSCRIPTION / AUTO-REORDER — Suscripción a productos
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite suscribirse a entregas recurrentes de productos
     * con descuento por suscripción.
     */

    function initProductSubscription() {
        document.querySelectorAll('[data-subscription-option]').forEach((container) => {
            if (container.dataset.subInit) return;
            container.dataset.subInit = 'true';

            const discount = container.dataset.subscriptionDiscount || '10';
            const frequencies = (container.dataset.subscriptionFrequencies || '7,14,30,60').split(',');
            const originalPrice = container.dataset.subscriptionPrice || '';

            container.className = 'ltms-subscription-option';
            container.innerHTML = `
                <div class="ltms-subscription-choices">
                    <label class="ltms-subscription-choice">
                        <input type="radio" name="purchase_type" value="one_time" checked>
                        <div class="ltms-subscription-choice-info">
                            <strong>Compra única</strong>
                            <span>${escapeHtml(originalPrice)}</span>
                        </div>
                    </label>
                    <label class="ltms-subscription-choice ltms-subscription-choice-recurring">
                        <input type="radio" name="purchase_type" value="subscription">
                        <div class="ltms-subscription-choice-info">
                            <strong>🔄 Suscripción recurrente</strong>
                            <span class="ltms-subscription-discount">Ahorra ${escapeHtml(discount)}% en cada pedido</span>
                        </div>
                    </label>
                </div>
                <div class="ltms-subscription-frequency" style="display:none;">
                    <label>Elige la frecuencia:</label>
                    <div class="ltms-subscription-frequencies">
                        ${frequencies.map((f, i) => {
                            const days = parseInt(f, 10);
                            const label = days === 7 ? 'Semanal' : days === 14 ? 'Cada 2 semanas' : days === 30 ? 'Mensual' : days === 60 ? 'Bimensual' : `Cada ${days} días`;
                            return `<label class="ltms-subscription-freq"><input type="radio" name="subscription_freq" value="${f}" ${i === 0 ? 'checked' : ''}> <span>${label}</span></label>`;
                        }).join('')}
                    </div>
                </div>
            `;

            const subCheck = container.querySelector('input[value="subscription"]');
            const freqSection = container.querySelector('.ltms-subscription-frequency');

            subCheck.addEventListener('change', () => {
                freqSection.style.display = subCheck.checked ? 'block' : 'none';

                // Update price display
                const priceEl = document.querySelector(container.dataset.priceTarget || '[data-variant-price]');
                if (priceEl && subCheck.checked && originalPrice) {
                    const numericPrice = parseFloat(originalPrice.replace(/[^0-9.]/g, ''));
                    const discounted = numericPrice * (1 - parseFloat(discount) / 100);
                    priceEl.textContent = formatCurrency(discounted) + ' (-' + discount + '%)';
                }

                // UX-FAKE-4 FIX — Persist the subscription choice server-side.
                // Previously the toggle only fired a success toast and never
                // notified the backend, so when the user proceeded to checkout
                // the subscription option was silently dropped. We now POST to
                // ltms_toggle_subscription (registered in
                // class-ltms-frontend-checkout-handler.php) and only toast
                // success on server confirmation.
                const productId = container.dataset.productId || container.dataset.subscriptionOption || '';
                const isSubscribed = !!subCheck.checked;

                // Optimistically update the hidden input if present (so the
                // checkout form submission also carries the choice).
                const subHidden = container.querySelector('input[name="ltms_subscription"]');
                if (subHidden) subHidden.value = isSubscribed ? 'yes' : 'no';

                const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce && productId) {
                    jQuery.post(ajaxUrl, {
                        action: 'ltms_toggle_subscription',
                        nonce: ajaxNonce,
                        product_id: productId,
                        subscribe: isSubscribed,
                    }, (response) => {
                        if (response.success) {
                            if (isSubscribed) {
                                toast('success', '¡Suscripción activada!', `Ahorras ${discount}% en cada pedido recurrente.`);
                            } else {
                                toast('info', 'Compra única', 'Suscripción desactivada.');
                            }
                        } else {
                            toast('error', 'Error', response.data?.message || 'No se pudo guardar la preferencia.');
                            // Revert the toggle so the UI reflects the actual state.
                            subCheck.checked = !isSubscribed;
                            freqSection.style.display = subCheck.checked ? 'block' : 'none';
                        }
                    }).fail(() => {
                        toast('error', 'Error de conexión', 'No se pudo guardar la preferencia. Intenta de nuevo.');
                        subCheck.checked = !isSubscribed;
                        freqSection.style.display = subCheck.checked ? 'block' : 'none';
                    });
                } else if (isSubscribed) {
                    // No AJAX available — surface a warning instead of faking success.
                    toast('warning', 'Suscripción no disponible', 'No se pudo activar la suscripción en este momento. Intenta de nuevo más tarde.');
                    subCheck.checked = false;
                    freqSection.style.display = 'none';
                }
            });

            container.querySelector('input[value="one_time"]').addEventListener('change', () => {
                const priceEl = document.querySelector(container.dataset.priceTarget || '[data-variant-price]');
                if (priceEl) priceEl.textContent = originalPrice;
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 105. PICKUP POINT SELECTOR — Selector de puntos de recogida
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de puntos de recogida con lista de tiendas
     * físicas, horarios y disponibilidad.
     */

    function initPickupSelector() {
        document.querySelectorAll('[data-pickup-selector]').forEach((container) => {
            if (container.dataset.psInit) return;
            container.dataset.psInit = 'true';

            const points = JSON.parse(container.dataset.pickupPoints || '[]');
            if (!points.length) return;

            container.className = 'ltms-pickup-selector';
            container.innerHTML = `
                <div class="ltms-pickup-header">
                    <h4>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Selecciona punto de recogida
                    </h4>
                    <input type="text" class="ltms-pickup-search" placeholder="Buscar por ciudad o dirección..." aria-label="Buscar punto de recogida">
                </div>
                <div class="ltms-pickup-list">
                    ${points.map((p, i) => `
                        <label class="ltms-pickup-point ${!p.available ? 'unavailable' : ''}" data-pickup-search-text="${escapeHtml((p.name + ' ' + p.address + ' ' + p.city).toLowerCase())}">
                            <input type="radio" name="pickup_point" value="${p.id}" ${i === 0 ? 'checked' : ''} ${!p.available ? 'disabled' : ''}>
                            <div class="ltms-pickup-point-info">
                                <div class="ltms-pickup-point-name">${escapeHtml(p.name)}</div>
                                <div class="ltms-pickup-point-address">${escapeHtml(p.address)}, ${escapeHtml(p.city)}</div>
                                <div class="ltms-pickup-point-hours">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    ${escapeHtml(p.hours || 'Lun-Sab 9:00-18:00')}
                                </div>
                                ${p.available ? '<span class="ltms-pickup-point-badge">Disponible</span>' : '<span class="ltms-pickup-point-badge unavailable">No disponible</span>'}
                            </div>
                            <div class="ltms-pickup-point-distance">${escapeHtml(p.distance || '')}</div>
                        </label>
                    `).join('')}
                </div>
            `;

            // Search filter
            const searchInput = container.querySelector('.ltms-pickup-search');
            searchInput.addEventListener('input', () => {
                const query = searchInput.value.toLowerCase().trim();
                container.querySelectorAll('.ltms-pickup-point').forEach((el) => {
                    const text = el.dataset.pickupSearchText;
                    el.style.display = text.includes(query) ? '' : 'none';
                });
            });

            // Selection
            container.querySelectorAll('input[name="pickup_point"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    if (radio.checked) {
                        const point = points.find((p) => p.id == radio.value);
                        container.dispatchEvent(new CustomEvent('ltms:pickup-selected', {
                            detail: point,
                            bubbles: true,
                        }));
                    }
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 106. FLASH SALE — Oferta flash / Deal of the day
    // ═══════════════════════════════════════════════════════════

    /**
     * Banner de oferta flash con countdown integrado, descuento
     * destacado y CTA urgente. Ideal para páginas de inicio.
     */

    function initFlashSale() {
        document.querySelectorAll('[data-flash-sale]').forEach((container) => {
            if (container.dataset.fsInit) return;
            container.dataset.fsInit = 'true';

            const endTime = container.dataset.flashSale;
            const discount = container.dataset.flashDiscount || '50%';
            const productName = container.dataset.flashProduct || 'Producto destacado';
            const productImg = container.dataset.flashImage || '';
            const originalPrice = container.dataset.flashOriginalPrice || '';
            const salePrice = container.dataset.flashSalePrice || '';
            const productUrl = container.dataset.flashUrl || '#';

            // Task 67-A / UX-SOCIAL-2 FIX: the progress bar previously used
            // `Math.random()` to fabricate "vendidos · ¡Quedan pocas unidades!"
            // counts and a percentage width. That is fabricated scarcity and
            // violates Colombia's Estatuto del Consumidor (Ley 1480/2011 art.
            // 31 — información engañosa) and Mexico's LFPU. We now render the
            // progress block ONLY when the server provides real values via the
            // `data-flash-sold` (units sold) and `data-flash-stock` (units
            // remaining) attributes — typically populated from the WC product's
            // `total_sales` and `_stock` meta. If either attribute is missing,
            // the entire progress block is omitted (no fabricated numbers).
            const soldRaw = container.dataset.flashSold;
            const stockRaw = container.dataset.flashStock;
            const sold = soldRaw !== undefined ? parseInt(soldRaw, 10) : NaN;
            const stock = stockRaw !== undefined ? parseInt(stockRaw, 10) : NaN;
            const hasRealData = !Number.isNaN(sold) && sold >= 0
                && !Number.isNaN(stock) && stock >= 0
                && (sold + stock) > 0;
            const soldPct = hasRealData
                ? Math.min(100, Math.max(0, Math.round((sold / (sold + stock)) * 100)))
                : 0;
            const progressBlock = hasRealData
                ? `
                <div class="ltms-flash-sale-progress" role="progressbar" aria-valuenow="${soldPct}" aria-valuemin="0" aria-valuemax="100" aria-label="${sold} vendidos, ${stock} disponibles">
                    <div class="ltms-flash-sale-progress-bar" style="width:${soldPct}%;"></div>
                    <span class="ltms-flash-sale-progress-text">${sold} vendidos${stock > 0 ? ` · ¡Quedan ${stock} unidades!` : ' · ¡Agotado!'}</span>
                </div>`
                : '';

            container.className = 'ltms-flash-sale';
            container.innerHTML = `
                <div class="ltms-flash-sale-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    FLASH SALE · -${escapeHtml(discount)}
                </div>
                <div class="ltms-flash-sale-content">
                    <div class="ltms-flash-sale-image">
                        ${productImg ? `<img src="${escapeHtml(productImg)}" alt="${escapeHtml(productName)}">` : '<div class="ltms-flash-sale-no-img">⚡</div>'}
                    </div>
                    <div class="ltms-flash-sale-info">
                        <h3 class="ltms-flash-sale-name">${escapeHtml(productName)}</h3>
                        <div class="ltms-flash-sale-prices">
                            ${originalPrice ? `<span class="ltms-flash-sale-original">${escapeHtml(originalPrice)}</span>` : ''}
                            ${salePrice ? `<span class="ltms-flash-sale-price">${escapeHtml(salePrice)}</span>` : ''}
                        </div>
                        <div class="ltms-flash-sale-countdown" data-countdown="${escapeHtml(endTime)}" data-countdown-prefix="⏰ Termina en"></div>
                        <a href="${escapeHtml(productUrl)}" class="ltms-btn ltms-btn-primary ltms-flash-sale-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            ¡Comprar ahora!
                        </a>
                    </div>
                </div>
                ${progressBlock}
            `;

            // Init countdown inside
            const cdEl = container.querySelector('[data-countdown]');
            if (cdEl) createCountdown(cdEl, endTime);

            // Animate progress bar from 0 → soldPct (no fabricated randomness).
            // Only animate when real data was provided.
            if (hasRealData) {
                const bar = container.querySelector('.ltms-flash-sale-progress-bar');
                if (bar) {
                    bar.style.width = '0%';
                    bar.style.transition = 'width 2s ease-out';
                    setTimeout(() => {
                        bar.style.width = soldPct + '%';
                    }, 500);
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 107. WAITLIST — Lista de espera para productos agotados
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite a los usuarios unirse a una lista de espera cuando
     * un producto está agotado. Notifica automáticamente cuando
     * vuelve a estar disponible.
     */

    function initWaitlist() {
        document.querySelectorAll('[data-waitlist]').forEach((container) => {
            if (container.dataset.wlInit) return;
            container.dataset.wlInit = 'true';

            const productId = container.dataset.waitlist;
            const productName = container.dataset.waitlistName || '';

            container.className = 'ltms-waitlist';
            container.innerHTML = `
                <div class="ltms-waitlist-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div class="ltms-waitlist-content">
                    <strong>Producto agotado</strong>
                    <p>Te avisaremos por email cuando vuelva a estar disponible.</p>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-waitlist-btn" data-waitlist-trigger="${escapeHtml(productId)}" data-waitlist-name="${escapeHtml(productName)}">
                    Unirme a la lista de espera
                </button>
            `;
        });

        // Waitlist trigger
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-waitlist-trigger]');
            if (!btn) return;
            e.preventDefault();

            const productId = btn.dataset.waitlistTrigger;
            openWaitlistModal(productId, btn.dataset.waitlistName || '');
        });
    }

    function openWaitlistModal(productId, productName) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-waitlist-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-wl-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-wl-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        Lista de espera
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <p style="margin:0 0 16px;color:var(--ltms-gray-600);font-size:0.9rem;line-height:1.5;">
                        Te avisaremos por email en cuanto <strong>${escapeHtml(productName)}</strong> vuelva a estar disponible. ¡No te lo pierdas!
                    </p>
                    <div class="ltms-form-group">
                        <label for="ltms-wl-email">Email *</label>
                        <input type="email" id="ltms-wl-email" class="ltms-form-control" required placeholder="tu@email.com" data-validate="required|email">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-wl-phone">Teléfono (opcional)</label>
                        <input type="tel" id="ltms-wl-phone" class="ltms-form-control" placeholder="+57 300 000 0000">
                    </div>
                    <label class="ltms-checkbox-label" style="margin-bottom:16px;">
                        <input type="checkbox" id="ltms-wl-notify" checked>
                        Notificarme también por SMS/WhatsApp
                    </label>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-wl-submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Unirme
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        overlay.querySelector('#ltms-wl-submit').addEventListener('click', () => {
            const email = overlay.querySelector('#ltms-wl-email').value.trim();
            const phone = overlay.querySelector('#ltms-wl-phone').value.trim();
            const notify = overlay.querySelector('#ltms-wl-notify').checked;

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                toast('warning', 'Email requerido', 'Ingresa un email válido.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-wl-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Guardando...';

            // Task 67-B — Use the global ltmsUX bootstrap (available on every
            // page) and POST to `ltms_waitlist_subscribe` (registered in
            // class-ltms-frontend-checkout-handler.php). The previous code
            // gated on `ltmsDashboard` only (vendor dashboard) and faked a
            // success toast on the storefront, telling the user they were on
            // the list when nothing was sent.
            const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_waitlist_subscribe',
                    nonce: ajaxNonce,
                    product_id: productId,
                    email: email,
                    phone: phone,
                    notify_sms: notify,
                }, (response) => {
                    if (response.success) {
                        close();
                        toast('success', '✅ ¡Estás en la lista!', response.data?.message || 'Te avisaremos cuando el producto esté disponible.');
                        // Replace button text
                        const trigger = document.querySelector(`[data-waitlist-trigger="${CSS.escape(productId)}"]`);
                        if (trigger) {
                            trigger.replaceWith(
                                Object.assign(document.createElement('div'), {
                                    className: 'ltms-waitlist-joined',
                                    innerHTML: '✓ Estás en la lista de espera',
                                })
                            );
                        }
                    } else {
                        toast('error', 'Error', response.data?.message || response.data || 'No se pudo completar.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                    }
                }).fail(() => {
                    toast('error', 'Error de conexión', 'No se pudo completar. Intenta de nuevo.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                });
            } else {
                // UX-FAKE FIX — do NOT fake success. Surface a real error so
                // the user knows the subscription was not saved.
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                toast('error', 'No disponible', 'No se pudo registrar tu email en este momento. Recarga la página e inténtalo de nuevo.');
            }
        });

        setTimeout(() => overlay.querySelector('#ltms-wl-email').focus(), 300);
    }

    LTMS.UX.openWaitlistModal = openWaitlistModal;

    // ═══════════════════════════════════════════════════════════
    // 108. PRODUCT BUNDLE — Constructor de paquetes
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite crear bundles personalizados: el usuario selecciona
     * productos y ve el precio total con descuento por bundle.
     */

    function initProductBundle() {
        document.querySelectorAll('[data-product-bundle]').forEach((container) => {
            if (container.dataset.pbInit) return;
            container.dataset.pbInit = 'true';

            const discount = parseFloat(container.dataset.bundleDiscount || '10');
            const minItems = parseInt(container.dataset.bundleMin || '2', 10);
            const items = container.querySelectorAll('[data-bundle-item]');
            const summaryEl = container.querySelector('[data-bundle-summary]') || createBundleSummary(container);

            function updateBundle() {
                const selected = [...items].filter((item) => item.querySelector('input[type="checkbox"]')?.checked);
                const count = selected.length;
                const subtotal = selected.reduce((sum, item) => {
                    return sum + parseFloat(item.dataset.bundlePrice || '0');
                }, 0);

                const hasDiscount = count >= minItems;
                const discountAmount = hasDiscount ? subtotal * (discount / 100) : 0;
                const total = subtotal - discountAmount;

                // Update items visual
                items.forEach((item) => {
                    item.classList.toggle('selected', item.querySelector('input')?.checked);
                });

                // Update summary
                summaryEl.innerHTML = `
                    <div class="ltms-bundle-summary-count">
                        <strong>${count}</strong> producto${count !== 1 ? 's' : ''} seleccionado${count !== 1 ? 's' : ''}
                        ${count < minItems ? `<span class="ltms-bundle-min-hint">· Selecciona ${minItems - count} más para descuento</span>` : ''}
                    </div>
                    ${count > 0 ? `
                        <div class="ltms-bundle-summary-prices">
                            <div class="ltms-bundle-summary-row">
                                <span>Subtotal:</span>
                                <span>${formatCurrency(subtotal)}</span>
                            </div>
                            ${hasDiscount ? `
                                <div class="ltms-bundle-summary-row ltms-bundle-discount">
                                    <span>Descuento (${discount}%):</span>
                                    <span>-${formatCurrency(discountAmount)}</span>
                                </div>
                                <div class="ltms-bundle-summary-row ltms-bundle-total">
                                    <span>Total:</span>
                                    <strong>${formatCurrency(total)}</strong>
                                </div>
                            ` : `
                                <div class="ltms-bundle-summary-row ltms-bundle-total">
                                    <span>Total:</span>
                                    <strong>${formatCurrency(subtotal)}</strong>
                                </div>
                            `}
                        </div>
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-full ltms-bundle-add-cart" ${count < 1 ? 'disabled' : ''}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Añadir ${count} al carrito ${hasDiscount ? `(${formatCurrency(total)})` : ''}
                        </button>
                    ` : ''}
                `;

                // Add to cart
                const addBtn = summaryEl.querySelector('.ltms-bundle-add-cart');
                if (addBtn) {
                    addBtn.addEventListener('click', () => {
                        const productIds = selected.map((item) => item.dataset.bundleItem);

                        // UX-FAKE-3 FIX — Previously this handler only fired a
                        // success toast and `announce()` without ever POSTing
                        // the bundle to the server. The user was told the
                        // bundle was added to the cart while nothing happened.
                        // Now we POST to ltms_add_bundle_to_cart (registered
                        // in class-ltms-frontend-checkout-handler.php) and
                        // only toast success when the server confirms.
                        if (!productIds.length) {
                            toast('warning', 'Selecciona productos', 'Elige al menos un producto del bundle.');
                            return;
                        }

                        // Prefer the global ltmsUX bootstrap (available on
                        // every page) and fall back to ltmsDashboard for the
                        // vendor dashboard context.
                        const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                        const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                        if (typeof jQuery === 'undefined' || !ajaxUrl || !ajaxNonce) {
                            toast('error', 'No disponible', 'No se pudo agregar el bundle. Recarga la página e inténtalo de nuevo.');
                            return;
                        }

                        const originalHtml = addBtn.innerHTML;
                        addBtn.disabled = true;
                        addBtn.innerHTML = '<span class="ltms-spinner"></span> Añadiendo...';

                        jQuery.post(ajaxUrl, {
                            action: 'ltms_add_bundle_to_cart',
                            nonce: ajaxNonce,
                            product_ids: productIds,
                        }, (response) => {
                            if (response.success) {
                                toast('success', '¡Éxito!', response.data?.message || 'Bundle agregado al carrito');
                                announce(`Bundle de ${productIds.length} productos añadido al carrito`);
                                // Update cart count badge if the server returned one.
                                if (response.data?.cart_count !== undefined) {
                                    document.querySelectorAll('.ltms-sf-cart-count, .cart-count').forEach((el) => {
                                        el.textContent = response.data.cart_count;
                                        el.style.display = response.data.cart_count > 0 ? '' : 'none';
                                    });
                                }
                                // Trigger WC fragments refresh so mini-cart widgets update.
                                if (typeof jQuery !== 'undefined' && jQuery(document.body).triggerHandler) {
                                    jQuery(document.body).trigger('wc_fragment_refresh');
                                }
                            } else {
                                toast('error', 'Error', response.data?.message || 'No se pudo agregar el bundle');
                            }
                        }).fail(() => {
                            toast('error', 'Error de conexión', 'No se pudo agregar el bundle. Intenta de nuevo.');
                        }).always(() => {
                            addBtn.disabled = false;
                            addBtn.innerHTML = originalHtml;
                        });
                    });
                }
            }

            items.forEach((item) => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.addEventListener('change', updateBundle);
                }
                item.addEventListener('click', (e) => {
                    if (e.target !== checkbox) {
                        checkbox.checked = !checkbox.checked;
                        updateBundle();
                    }
                });
            });

            updateBundle();
        });
    }

    function createBundleSummary(container) {
        const summary = document.createElement('div');
        summary.className = 'ltms-bundle-summary';
        summary.setAttribute('data-bundle-summary', '');
        container.appendChild(summary);
        return summary;
    }

    // ═══════════════════════════════════════════════════════════
    // 110. VENDOR RATING — Calificación de vendedores
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de calificación de vendedores con métricas:
     * rating general, tiempo de respuesta, calidad de envío,
     * comunicación y precisión de descripción.
     */

    function initVendorRating() {
        document.querySelectorAll('[data-vendor-rating]').forEach((container) => {
            if (container.dataset.vrInit) return;
            container.dataset.vrInit = 'true';

            const rating = parseFloat(container.dataset.vendorRating || '0');
            const reviewCount = parseInt(container.dataset.vendorReviews || '0', 10);
            const metrics = JSON.parse(container.dataset.vendorMetrics || '{}');

            const defaultMetrics = {
                quality: rating,
                shipping: rating,
                communication: rating,
                accuracy: rating,
            };
            const m = { ...defaultMetrics, ...metrics };

            const metricLabels = {
                quality: 'Calidad del producto',
                shipping: 'Velocidad de envío',
                communication: 'Comunicación',
                accuracy: 'Precisión de descripción',
            };

            container.className = 'ltms-vendor-rating';
            container.innerHTML = `
                <div class="ltms-vendor-rating-summary">
                    <div class="ltms-vendor-rating-score">
                        <span class="ltms-vendor-rating-num">${rating.toFixed(1)}</span>
                        <div class="ltms-vendor-rating-stars">${renderStars(rating)}</div>
                        <span class="ltms-vendor-rating-count">${reviewCount} reseñas</span>
                    </div>
                    <div class="ltms-vendor-rating-metrics">
                        ${Object.entries(m).map(([key, val]) => `
                            <div class="ltms-vendor-rating-metric">
                                <span class="ltms-vendor-rating-metric-label">${escapeHtml(metricLabels[key] || key)}</span>
                                <div class="ltms-vendor-rating-metric-bar">
                                    <div class="ltms-vendor-rating-metric-fill" style="width:${(val / 5) * 100}%;"></div>
                                </div>
                                <span class="ltms-vendor-rating-metric-value">${val.toFixed(1)}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 111. PRICE MATCH — Garantía de mejor precio
    // ═══════════════════════════════════════════════════════════

    /**
     * Formulario de garantía de mejor precio: el usuario reporta
     * un precio menor en otro sitio y recibe un reembolso o
     * ajuste de precio.
     */

    function initPriceMatch() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-price-match]');
            if (!trigger) return;
            e.preventDefault();

            const productId = trigger.dataset.priceMatch;
            const currentPrice = trigger.dataset.currentPrice || '';
            const productName = trigger.dataset.productName || '';

            openPriceMatchModal(productId, productName, currentPrice);
        });
    }

    function openPriceMatchModal(productId, productName, currentPrice) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-price-match-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-pm-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-pm-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        Garantía de mejor precio
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-price-match-info">
                        <p>¿Encontraste <strong>${escapeHtml(productName)}</strong> más barato en otro sitio? ¡Igualamos el precio!</p>
                        <p class="ltms-price-match-current">Precio actual: <strong>${escapeHtml(currentPrice)}</strong></p>
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-competitor">URL del producto en la tienda competidora *</label>
                        <input type="url" id="ltms-pm-competitor" class="ltms-form-control" required placeholder="https://otra-tienda.com/producto" data-validate="required|url">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-price">Precio encontrado *</label>
                        <input type="number" id="ltms-pm-price" class="ltms-form-control" required placeholder="0" min="0" step="0.01" data-validate="required|number|min:0">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-email">Tu email *</label>
                        <input type="email" id="ltms-pm-email" class="ltms-form-control" required placeholder="tu@email.com" data-validate="required|email">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-screenshot">Captura de pantalla (opcional)</label>
                        <input type="file" id="ltms-pm-screenshot" class="ltms-file-input" accept="image/*">
                    </div>
                    <div class="ltms-price-match-terms">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <span>El producto debe ser idéntico (misma marca, modelo, condición). Válido dentro de los 7 días posteriores a la compra.</span>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-pm-submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Enviar solicitud
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        overlay.querySelector('#ltms-pm-submit').addEventListener('click', () => {
            const url = overlay.querySelector('#ltms-pm-competitor').value.trim();
            const price = overlay.querySelector('#ltms-pm-price').value;
            const email = overlay.querySelector('#ltms-pm-email').value.trim();

            if (!url || !price || !email) {
                toast('warning', 'Campos requeridos', 'Completa todos los campos obligatorios.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-pm-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            setTimeout(() => {
                close();
                toast('success', '✅ Solicitud enviada', 'Revisaremos tu solicitud en 24-48 horas.');
            }, 1000);
        });
    }

    LTMS.UX.openPriceMatchModal = openPriceMatchModal;

    // ═══════════════════════════════════════════════════════════
    // 112. WHATSAPP ORDER — Botón de pedido por WhatsApp
    // ═══════════════════════════════════════════════════════════

    /**
     * Botón flotante o inline que abre WhatsApp con un mensaje
     * pre-rellenado con los datos del producto/carrito.
     */

    function initWhatsAppOrder() {
        document.querySelectorAll('[data-whatsapp-order]').forEach((btn) => {
            if (btn.dataset.waInit) return;
            btn.dataset.waInit = 'true';

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const phone = btn.dataset.whatsappOrder || '';
                const productName = btn.dataset.productName || '';
                const productUrl = btn.dataset.productUrl || window.location.href;
                const productPrice = btn.dataset.productPrice || '';
                const vendorName = btn.dataset.vendorName || '';

                let message = '¡Hola! 👋\n\n';
                message += `Quiero hacer un pedido:\n\n`;
                message += `📦 *Producto:* ${productName}\n`;
                if (productPrice) message += `💰 *Precio:* ${productPrice}\n`;
                if (vendorName) message += `🏪 *Tienda:* ${vendorName}\n`;
                message += `🔗 *Link:* ${productUrl}\n\n`;
                message += `¿Está disponible? ¿Cómo procedo con el pago?`;

                const url = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
                window.open(url, '_blank', 'width=600,height=600');

                toast('success', 'Abriendo WhatsApp', 'Te redirigimos al chat para completar tu pedido.');
                announce('Abriendo WhatsApp para realizar pedido');
            });
        });

        // Floating WhatsApp button
        const floatingBtn = document.querySelector('[data-whatsapp-float]');
        if (floatingBtn && !floatingBtn.dataset.waFloatInit) {
            floatingBtn.dataset.waFloatInit = 'true';
            floatingBtn.className = 'ltms-whatsapp-float';
            floatingBtn.setAttribute('aria-label', 'Pedir por WhatsApp');
            floatingBtn.innerHTML = `
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.693.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            `;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 114. SAVE CART — Guardar carrito para después
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite guardar el carrito actual para recuperarlo después,
     * útil cuando el usuario quiere seguir navegando.
     */

    function initSaveCart() {
        // Save cart button
        document.addEventListener('click', (e) => {
            const saveBtn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-save-cart]');
            if (!saveBtn) return;
            e.preventDefault();

            const cartItems = [];
            document.querySelectorAll('[data-cart-item-key]').forEach((item) => {
                cartItems.push({
                    key: item.dataset.cartItemKey,
                    name: item.querySelector('.ltms-cart-item-name, .product-name')?.textContent?.trim() || '',
                    price: item.querySelector('.ltms-cart-item-price, .product-price')?.textContent?.trim() || '',
                    qty: item.querySelector('.ltms-cart-qty-value, .qty')?.textContent?.trim() || '1',
                });
            });

            if (!cartItems.length) {
                toast('warning', 'Carrito vacío', 'No hay items para guardar.');
                return;
            }

            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            savedCarts.unshift({
                id: Date.now(),
                date: new Date().toISOString(),
                items: cartItems,
                count: cartItems.length,
            });

            // Keep max 5 saved carts
            const trimmed = savedCarts.slice(0, 5);
            localStorage.setItem('ltms-saved-carts', JSON.stringify(trimmed));

            toast('success', '✅ Carrito guardado', `${cartItems.length} producto(s) guardados para después.`);
            announce(`Carrito guardado con ${cartItems.length} productos`);
        });

        // Restore cart button
        document.addEventListener('click', (e) => {
            const restoreBtn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-restore-cart]');
            if (!restoreBtn) return;
            e.preventDefault();

            const cartId = restoreBtn.dataset.restoreCart;
            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            const cart = savedCarts.find((c) => c.id == cartId);

            if (!cart) {
                toast('error', 'No encontrado', 'El carrito guardado ya no existe.');
                return;
            }

            // Show confirmation
            const overlay = document.createElement('div');
            overlay.className = 'ltms-modal-overlay';
            overlay.innerHTML = `
                <div class="ltms-modal" role="dialog" aria-modal="true">
                    <div class="ltms-modal-header">
                        <h3 class="ltms-modal-title">Restaurar carrito</h3>
                        <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="ltms-modal-body">
                        <p style="margin:0 0 14px;color:var(--ltms-gray-600);">¿Restaurar ${cart.count} producto(s) guardados el ${new Date(cart.date).toLocaleDateString('es-CO')}?</p>
                        <div class="ltms-saved-cart-items">
                            ${cart.items.map((item) => `
                                <div class="ltms-saved-cart-item">
                                    <span>${escapeHtml(item.name)}</span>
                                    <span style="color:var(--ltms-gray-500);font-size:0.8rem;">${escapeHtml(item.price)} × ${escapeHtml(item.qty)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    <div class="ltms-modal-footer">
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                        <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-restore-confirm">Restaurar</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

            const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
            const close = () => {
                cleanup();
                overlay.classList.remove('ltms-modal-open');
                setTimeout(() => overlay.remove(), 250);
            };

            overlay.querySelectorAll('.ltms-modal-close').forEach((b) => b.addEventListener('click', close));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

            overlay.querySelector('#ltms-restore-confirm').addEventListener('click', () => {
                close();
                toast('success', 'Carrito restaurado', `${cart.count} producto(s) añadidos. Recarga la página para verlos.`);
                setTimeout(() => location.reload(), 1500);
            });
        });

        // Delete saved cart
        document.addEventListener('click', (e) => {
            const deleteBtn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-delete-saved-cart]');
            if (!deleteBtn) return;
            e.preventDefault();

            const cartId = deleteBtn.dataset.deleteSavedCart;
            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            const filtered = savedCarts.filter((c) => c.id != cartId);
            localStorage.setItem('ltms-saved-carts', JSON.stringify(filtered));

            deleteBtn.closest('.ltms-saved-cart-entry')?.remove();
            toast('info', 'Carrito eliminado', '');
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 115. SHIPPING CALCULATOR — Calculadora de envío
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que calcula el costo de envío estimado
     * basado en código postal/ciudad antes del checkout.
     */

    function initShippingCalculator() {
        document.querySelectorAll('[data-shipping-calculator]').forEach((container) => {
            if (container.dataset.scInit) return;
            container.dataset.scInit = 'true';

            container.className = 'ltms-shipping-calculator';
            container.innerHTML = `
                <div class="ltms-shipping-calc-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <h4>Calcular envío</h4>
                </div>
                <div class="ltms-shipping-calc-form">
                    <select class="ltms-shipping-calc-country" aria-label="País">
                        <option value="CO">🇨🇴 Colombia</option>
                        <option value="MX">🇲🇽 México</option>
                    </select>
                    <input type="text" class="ltms-shipping-calc-city" placeholder="Ciudad o código postal" aria-label="Ciudad">
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-shipping-calc-btn">
                        Calcular
                    </button>
                </div>
                <div class="ltms-shipping-calc-results" style="display:none;"></div>
            `;

            const resultsEl = container.querySelector('.ltms-shipping-calc-results');
            const calcBtn = container.querySelector('.ltms-shipping-calc-btn');
            const cityInput = container.querySelector('.ltms-shipping-calc-city');
            const countrySelect = container.querySelector('.ltms-shipping-calc-country');

            calcBtn.addEventListener('click', () => {
                const city = cityInput.value.trim();
                if (!city) {
                    toast('warning', 'Campo requerido', 'Ingresa tu ciudad o código postal.');
                    return;
                }

                calcBtn.disabled = true;
                calcBtn.innerHTML = '<span class="ltms-spinner"></span>';

                // Simulated rates (in production, use AJAX to Aveonline/Heka/Deprisa)
                const rates = [
                    { name: 'Envío Estándar', price: '$8.500', days: '3-5 días', icon: '📦' },
                    { name: 'Envío Express', price: '$15.000', days: '1-2 días', icon: '⚡' },
                    { name: 'Recogida en tienda', price: 'Gratis', days: 'Disponible hoy', icon: '🏪' },
                ];

                setTimeout(() => {
                    resultsEl.style.display = 'block';
                    resultsEl.innerHTML = `
                        <div class="ltms-shipping-calc-rates">
                            ${rates.map((rate, i) => `
                                <label class="ltms-shipping-rate ${i === 0 ? 'selected' : ''}">
                                    <input type="radio" name="shipping_rate" value="${escapeHtml(rate.name)}" ${i === 0 ? 'checked' : ''}>
                                    <div class="ltms-shipping-rate-icon">${rate.icon}</div>
                                    <div class="ltms-shipping-rate-info">
                                        <strong>${escapeHtml(rate.name)}</strong>
                                        <span>${escapeHtml(rate.days)}</span>
                                    </div>
                                    <div class="ltms-shipping-rate-price ${rate.price === 'Gratis' ? 'free' : ''}">${escapeHtml(rate.price)}</div>
                                </label>
                            `).join('')}
                        </div>
                    `;

                    calcBtn.disabled = false;
                    calcBtn.textContent = 'Recalcular';

                    // Selection
                    resultsEl.querySelectorAll('.ltms-shipping-rate').forEach((el) => {
                        el.addEventListener('click', () => {
                            resultsEl.querySelectorAll('.ltms-shipping-rate').forEach((r) => r.classList.remove('selected'));
                            el.classList.add('selected');
                            el.querySelector('input').checked = true;
                        });
                    });

                    announce(`${rates.length} opciones de envío disponibles`);
                }, 800);
            });

            // Enter key
            cityInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); calcBtn.click(); }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 116. PRODUCT Q&A — Preguntas y respuestas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sección de preguntas y respuestas de productos:
     * los usuarios pueden hacer preguntas y ver respuestas
     * del vendedor y de otros compradores.
     */

    function initProductQA() {
        document.querySelectorAll('[data-product-qa]').forEach((container) => {
            if (container.dataset.qaInit) return;
            container.dataset.qaInit = 'true';

            const productId = container.dataset.productQa;
            const questions = JSON.parse(container.dataset.qaQuestions || '[]');

            container.className = 'ltms-product-qa';
            container.innerHTML = `
                <div class="ltms-qa-header">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Preguntas y respuestas
                    </h3>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-qa-ask">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Hacer pregunta
                    </button>
                </div>
                <div class="ltms-qa-list">
                    ${questions.length ? questions.map((q) => `
                        <div class="ltms-qa-item">
                            <div class="ltms-qa-question">
                                <div class="ltms-qa-question-avatar">👤</div>
                                <div class="ltms-qa-question-content">
                                    <div class="ltms-qa-question-text">${escapeHtml(q.question)}</div>
                                    <div class="ltms-qa-meta">${escapeHtml(q.author || 'Anónimo')} · ${escapeHtml(q.date || '')}</div>
                                </div>
                            </div>
                            ${q.answer ? `
                                <div class="ltms-qa-answer">
                                    <div class="ltms-qa-answer-avatar">🏪</div>
                                    <div class="ltms-qa-answer-content">
                                        <div class="ltms-qa-answer-text">${escapeHtml(q.answer)}</div>
                                        <div class="ltms-qa-meta">Vendedor · ${escapeHtml(q.answer_date || '')}</div>
                                    </div>
                                </div>
                            ` : '<div class="ltms-qa-pending">⏳ Pendiente de respuesta</div>'}
                        </div>
                    `).join('') : '<div class="ltms-qa-empty">Aún no hay preguntas. ¡Sé el primero en preguntar!</div>'}
                </div>
            `;

            // Ask question
            container.querySelector('#ltms-qa-ask').addEventListener('click', () => {
                openQAForm(productId, container);
            });
        });
    }

    function openQAForm(productId, container) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-qa-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-qa-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-qa-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Hacer una pregunta
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-form-group">
                        <label for="ltms-qa-input">Tu pregunta *</label>
                        <textarea id="ltms-qa-input" class="ltms-form-control" rows="3" placeholder="Ej: ¿Este producto viene en otros colores?" maxlength="500" data-validate="required|minlength:10"></textarea>
                        <small class="ltms-field-hint"><span id="ltms-qa-counter">0</span>/500 caracteres</small>
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-qa-name">Tu nombre (opcional)</label>
                        <input type="text" id="ltms-qa-name" class="ltms-form-control" placeholder="Anónimo">
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-qa-submit">Enviar pregunta</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((b) => b.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        const textarea = overlay.querySelector('#ltms-qa-input');
        const counter = overlay.querySelector('#ltms-qa-counter');
        textarea.addEventListener('input', () => { counter.textContent = textarea.value.length; });

        overlay.querySelector('#ltms-qa-submit').addEventListener('click', () => {
            const question = textarea.value.trim();
            if (question.length < 10) {
                toast('warning', 'Muy corta', 'Tu pregunta debe tener al menos 10 caracteres.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-qa-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_submit_question',
                    nonce: ltmsDashboard.nonce,
                    product_id: productId,
                    question: question,
                    author: overlay.querySelector('#ltms-qa-name').value.trim(),
                }, (response) => {
                    if (response.success) {
                        close();
                        toast('success', '✅ Pregunta enviada', 'El vendedor responderá pronto.');
                        // Add to list
                        const list = container.querySelector('.ltms-qa-list');
                        const empty = list.querySelector('.ltms-qa-empty');
                        if (empty) empty.remove();

                        const item = document.createElement('div');
                        item.className = 'ltms-qa-item';
                        item.innerHTML = `
                            <div class="ltms-qa-question">
                                <div class="ltms-qa-question-avatar">👤</div>
                                <div class="ltms-qa-question-content">
                                    <div class="ltms-qa-question-text">${escapeHtml(question)}</div>
                                    <div class="ltms-qa-meta">Tú · hace un momento</div>
                                </div>
                            </div>
                            <div class="ltms-qa-pending">⏳ Pendiente de respuesta</div>
                        `;
                        list.insertBefore(item, list.firstChild);
                    } else {
                        toast('error', 'Error', response.data || 'No se pudo enviar.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Enviar pregunta';
                    }
                });
            } else {
                setTimeout(() => {
                    close();
                    toast('success', '✅ Pregunta enviada', 'El vendedor responderá pronto.');
                }, 800);
            }
        });

        setTimeout(() => textarea.focus(), 300);
    }

    // ═══════════════════════════════════════════════════════════
    // 117. NEWSLETTER SIGNUP — Captura de email con incentivo
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal/banner de newsletter con incentivo de descuento
     * para capturar emails de nuevos visitantes.
     */

    function initNewsletterSignup() {
        // No mostrar si ya se suscribió o cerró
        try {
            if (localStorage.getItem('ltms-newsletter-closed') === 'true') return;
            if (localStorage.getItem('ltms-newsletter-subscribed') === 'true') return;
        } catch (e) { return; }

        // No mostrar en login/admin/checkout
        if (document.querySelector('.ltms-auth-container')) return;
        if (document.body.classList.contains('wp-admin')) return;
        if (document.body.classList.contains('woocommerce-checkout')) return;

        // Solo a usuarios no logueados o después de 60s
        const delay = document.body.classList.contains('logged-in') ? 120000 : 45000;

        setTimeout(() => {
            const overlay = document.createElement('div');
            overlay.className = 'ltms-modal-overlay ltms-newsletter-overlay';
            overlay.innerHTML = `
                <div class="ltms-newsletter-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-nl-title">
                    <button type="button" class="ltms-newsletter-close" aria-label="Cerrar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                    <div class="ltms-newsletter-content">
                        <div class="ltms-newsletter-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </div>
                        <h2 id="ltms-nl-title">¡Bienvenido a Lo Tengo! 👋</h2>
                        <p>Suscríbete y recibe <strong>ofertas exclusivas</strong>, novedades y acceso anticipado a promociones.</p>
                        <form id="ltms-newsletter-form" class="ltms-newsletter-form">
                            <input type="email" id="ltms-nl-email" placeholder="tu@email.com" required aria-label="Email" data-validate="required|email">
                            <button type="submit" class="ltms-btn ltms-btn-primary">
                                Suscribirme
                            </button>
                        </form>
                        <div class="ltms-newsletter-terms">
                            Al suscribirte aceptas recibir emails de marketing. Puedes darte de baja en cualquier momento.
                        </div>
                        <div class="ltms-newsletter-trust">
                            <span>🔒 Spam-free</span>
                            <span>✓ Sin compromiso</span>
                            <span>🔔 Novedades y ofertas</span>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => overlay.classList.add('visible'));

            const close = () => {
                overlay.classList.remove('visible');
                document.body.style.overflow = '';
                try { localStorage.setItem('ltms-newsletter-closed', 'true'); } catch (e) {}
                setTimeout(() => overlay.remove(), 400);
            };

            overlay.querySelector('.ltms-newsletter-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

            // Form submit
            overlay.querySelector('#ltms-newsletter-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const email = overlay.querySelector('#ltms-nl-email').value.trim();
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    toast('warning', 'Email inválido', 'Ingresa un email correcto.');
                    return;
                }

                const btn = overlay.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerHTML = '<span class="ltms-spinner"></span>';

                // Simulate AJAX
                setTimeout(() => {
                    try { localStorage.setItem('ltms-newsletter-subscribed', 'true'); } catch (e) {}

                    overlay.querySelector('.ltms-newsletter-content').innerHTML = `
                        <div class="ltms-newsletter-success">
                            <div class="ltms-newsletter-success-icon">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <h2>¡Suscripción confirmada! 🎉</h2>
                            <p>Gracias por unirte a Lo Tengo. Recibirás novedades y ofertas exclusivas directamente en tu correo.</p>
                            <p class="ltms-newsletter-success-hint">Puedes darte de baja cuando quieras.</p>
                        </div>
                    `;

                    setTimeout(() => {
                        close();
                        toast('success', '🎉 ¡Bienvenido!', 'Revisa tu email para más detalles.');
                    }, 5000);
                }, 1000);
            });

            setTimeout(() => overlay.querySelector('#ltms-nl-email').focus(), 500);
        }, delay);
    }

    // ═══════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════
    // INIT — Punto de entrada del bundle
    // ═══════════════════════════════════════════════════════════

    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAll);
        } else {
            initAll();
        }
    }

    function initAll() {
        try {
            initCartDrawer();
            initCookieConsent();
            initQuickView();
            initWishlist();
            initRecentlyViewed();
            initSocialShare();
            initCompare();
            initPriceRanges();
            initImageZoom();
            initProductCarousels();
            initQuantitySteppers();
            initCouponInputs();
            initFilterSidebar();
            initMultiStepCheckout();
            initAddressAutocomplete();
            initReviewSystem();
            initOrderSuccess();
            initBackorderNotice();
            initStickyAddToCart();
            initProductTabs();
            initVariantSelector();
            initTrustBadges();
            initDeliveryDatePickers();
            initGiftWrapping();
            initAbandonedCartRecovery();
            initProductRecommendations();
            initSearchAutocomplete();
            initMultiCurrency();
            initSocialProof();
            initSizeGuide();
            initProductVideo();
            initRecentSearches();
            initMultiVendorCart();
            initEstimatedDelivery();
            initProductSubscription();
            initPickupSelector();
            initFlashSale();
            initWaitlist();
            initProductBundle();
            initVendorRating();
            initPriceMatch();
            initWhatsAppOrder();
            initSaveCart();
            initShippingCalculator();
            initProductQA();
            initNewsletterSignup();


        } catch (err) {
            console.error('[LTMS.UX] Error inicializando:', err);
        }
    }

    // Auto-init
    init();

})();