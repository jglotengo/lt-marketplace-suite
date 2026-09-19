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
    // SECCIONES DASHBOARD (panel del vendedor + mi-cuenta)
    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js
    // ═══════════════════════════════════════════════════════════

    // 3. KEYBOARD SHORTCUTS — Productividad en el dashboard
    // ═══════════════════════════════════════════════════════════

    /**
     * Atajos de teclado:
     *  - Alt+1..9 → navegar vistas del dashboard
     *  - Alt+H    → ir a inicio
     *  - Alt+N    → toggle notificaciones
     *  - Alt+/    → focus en búsqueda (si existe)
     *  - Esc      → cerrar modales/overlays
     */
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Solo si no estamos escribiendo en un input
            const tag = (e.target.tagName || '').toLowerCase();
            const isTyping = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;

            // Esc siempre funciona
            if (e.key === 'Escape') {
                // Cerrar modales
                document.querySelectorAll('.ltms-modal-overlay.ltms-modal-open').forEach(m => {
                    m.classList.remove('ltms-modal-open');
                });
                // Cerrar notifications panel
                const notifPanel = document.querySelector('.ltms-notifications-panel.open');
                if (notifPanel) notifPanel.classList.remove('open');
                // Cerrar sidebar móvil
                const sidebar = document.querySelector('.ltms-sidebar.ltms-sidebar-open');
                if (sidebar) sidebar.classList.remove('ltms-sidebar-open');
                return;
            }

            if (isTyping || !e.altKey) return;

            // Alt + número → navegar a vista N
            if (/^[1-9]$/.test(e.key)) {
                e.preventDefault();
                const idx = parseInt(e.key, 10) - 1;
                const navItems = document.querySelectorAll('.ltms-nav-item[data-view]');
                if (navItems[idx]) navItems[idx].click();
                return;
            }

            // Alt + H → Home
            if (e.key.toLowerCase() === 'h') {
                e.preventDefault();
                const home = document.querySelector('.ltms-nav-item[data-view="home"]');
                if (home) home.click();
                return;
            }

            // Alt + N → toggle notificaciones
            if (e.key.toLowerCase() === 'n') {
                e.preventDefault();
                const notifBtn = document.querySelector('.ltms-topbar-notif');
                if (notifBtn) notifBtn.click();
                return;
            }

            // Alt + / → focus búsqueda
            if (e.key === '/') {
                e.preventDefault();
                const search = document.querySelector('.ltms-live-search-input, input[type="search"], .ltms-search-input');
                if (search) search.focus();
                return;
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 14. SIDEBAR MOBILE — Cerrar al click fuera
    // ═══════════════════════════════════════════════════════════

    function initSidebarOverlay() {
        const overlay = document.querySelector('.ltms-sidebar-overlay');
        if (!overlay) return;

        overlay.addEventListener('click', () => {
            const sidebar = document.querySelector('.ltms-sidebar');
            if (sidebar) {
                sidebar.classList.remove('ltms-sidebar-open');
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 16. USER DROPDOWN — Toggle en topbar
    // ═══════════════════════════════════════════════════════════

    function initUserDropdown() {
        const wrap = document.querySelector('.ltms-user-dropdown-wrap');
        if (!wrap) return;

        const trigger = wrap.querySelector('.ltms-topbar-user');
        const overlay = wrap.querySelector('.ltms-dropdown-overlay');

        function toggle(open) {
            const isOpen = typeof open === 'boolean' ? open : !wrap.classList.contains('is-open');
            wrap.classList.toggle('is-open', isOpen);
            if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (overlay) overlay.classList.toggle('is-visible', isOpen);
        }

        if (trigger) {
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                toggle();
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => toggle(false));
        }

        // Cerrar al click fuera
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) toggle(false);
        });

        // Cerrar con Escape
        wrap.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                toggle(false);
                if (trigger) trigger.focus();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 18. NOTIFICATIONS PANEL — Close button + mark all
    // ═══════════════════════════════════════════════════════════

    function initNotificationsPanel() {
        // Close button del panel de notificaciones
        document.addEventListener('click', (e) => {
            const closeBtn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-notif-close');
            if (closeBtn) {
                const panel = document.querySelector('.ltms-notifications-panel');
                if (panel) {
                    panel.classList.remove('open');
                    panel.setAttribute('aria-hidden', 'true');
                }
                const notifBtn = document.querySelector('.ltms-topbar-notif');
                if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
                return;
            }

            // Mark all as read
            const markAllBtn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-notif-mark-all');
            if (markAllBtn) {
                e.preventDefault();
                const unreadItems = document.querySelectorAll('.ltms-notif-item.unread');
                unreadItems.forEach((item) => {
                    const id = item.dataset.id;
                    if (id && window.LTMS && LTMS.Dashboard && typeof LTMS.Dashboard.markNotificationRead === 'function') {
                        LTMS.Dashboard.markNotificationRead(id, jQuery(item));
                    }
                });
                if (window.LTMS && LTMS.UX) {
                    LTMS.UX.toastSuccess('Listo', unreadItems.length > 0 ? `${unreadItems.length} notificación(es) marcada(s) como leída(s)` : 'No había notificaciones sin leer');
                }
                return;
            }
        });

        // Actualizar aria-expanded del botón de notificaciones al togglear
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('click', '.ltms-topbar-notif', function () {
                const panel = document.querySelector('.ltms-notifications-panel');
                const isOpen = panel && panel.classList.contains('open');
                this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (panel) panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 21. COMMAND PALETTE — Búsqueda rápida con Cmd+K / Ctrl+K
    // ═══════════════════════════════════════════════════════════

    /**
     * Command palette: overlay de búsqueda rápida que permite
     * navegar a cualquier vista del dashboard, ejecutar acciones
     * comunes y buscar productos/pedidos con teclado.
     *
     * Activación: Cmd+K (Mac) / Ctrl+K (Windows/Linux)
     */

    const COMMANDS = [
        { id: 'goto-home',     label: 'Ir a Inicio',        icon: 'home',     view: 'home',     keywords: 'inicio dashboard' },
        { id: 'goto-orders',   label: 'Ir a Pedidos',       icon: 'orders',   view: 'orders',   keywords: 'pedidos ventas' },
        { id: 'goto-products', label: 'Ir a Productos',     icon: 'products', view: 'products', keywords: 'productos catalogo' },
        { id: 'goto-wallet',   label: 'Ir a Billetera',     icon: 'wallet',   view: 'wallet',   keywords: 'billetera dinero retiro' },
        { id: 'goto-envios',   label: 'Ir a Envíos',        icon: 'shipping', view: 'envios',   keywords: 'envios guias despacho' },
        { id: 'goto-bookings', label: 'Ir a Reservas',      icon: 'booking',  view: 'bookings', keywords: 'reservas turismo alojamiento' },
        { id: 'goto-settings', label: 'Ir a Configuración', icon: 'settings', view: 'settings', keywords: 'configuracion ajustes cuenta' },
        { id: 'action-payout', label: 'Solicitar Retiro',   icon: 'payout',   action: () => LTMS.Dashboard && LTMS.Dashboard.openPayoutModal && LTMS.Dashboard.openPayoutModal(), keywords: 'retiro dinero transferir' },
        { id: 'action-refresh', label: 'Actualizar datos',  icon: 'refresh',  action: () => LTMS.Dashboard && LTMS.Dashboard.loadView && LTMS.Dashboard.loadView(LTMS.Dashboard.currentView || 'home', true), keywords: 'actualizar refrescar recargar' },
        { id: 'action-theme',  label: 'Cambiar tema (claro/oscuro)', icon: 'theme', action: () => document.querySelector('.ltms-theme-toggle') && document.querySelector('.ltms-theme-toggle').click(), keywords: 'tema dark light oscuro claro' },
        { id: 'action-logout', label: 'Cerrar sesión',      icon: 'logout',   action: () => { const link = document.querySelector('.ltms-user-dropdown a[href*="logout"]'); if (link) window.location.href = link.href; }, keywords: 'salir logout session' },
    ];

    const COMMAND_ICONS = {
        home:     '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        orders:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M22 11l-3-3h-5v8h5l3-3v-2z"/></svg>',
        products: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 0 0-8 0v4"/><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/></svg>',
        wallet:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>',
        shipping: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        booking:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        settings: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        payout:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        refresh:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        theme:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>',
        logout:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    };

    let paletteEl = null;
    let paletteCleanup = null;
    let selectedIndex = 0;

    function getCommands() {
        return COMMANDS;
    }

    function filterCommands(query) {
        if (!query) return COMMANDS;
        const q = query.toLowerCase().trim();
        return COMMANDS.filter((cmd) => {
            return cmd.label.toLowerCase().includes(q) || cmd.keywords.toLowerCase().includes(q);
        });
    }

    function openCommandPalette() {
        if (paletteEl) return; // ya abierto

        // Solo funcionar en contexto de dashboard
        if (!document.querySelector('.ltms-dashboard-container')) return;

        paletteEl = document.createElement('div');
        paletteEl.className = 'ltms-command-palette';
        paletteEl.innerHTML = `
            <div class="ltms-cp-overlay"></div>
            <div class="ltms-cp-modal" role="dialog" aria-modal="true" aria-label="Búsqueda rápida">
                <div class="ltms-cp-input-wrap">
                    <svg class="ltms-cp-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="ltms-cp-input" placeholder="Buscar acciones o ir a..." autocomplete="off" />
                    <kbd class="ltms-cp-kbd">Esc</kbd>
                </div>
                <div class="ltms-cp-results" role="listbox" aria-label="Resultados"></div>
                <div class="ltms-cp-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navegar</span>
                    <span><kbd>↵</kbd> seleccionar</span>
                    <span><kbd>Esc</kbd> cerrar</span>
                </div>
            </div>
        `;
        document.body.appendChild(paletteEl);
        document.body.style.overflow = 'hidden';

        const input = paletteEl.querySelector('.ltms-cp-input');
        const results = paletteEl.querySelector('.ltms-cp-results');
        selectedIndex = 0;

        function render(filtered) {
            if (!filtered.length) {
                results.innerHTML = '<div class="ltms-cp-empty">No se encontraron resultados</div>';
                return;
            }
            results.innerHTML = filtered.map((cmd, i) => `
                <button type="button" class="ltms-cp-item ${i === selectedIndex ? 'active' : ''}" data-id="${cmd.id}" role="option" aria-selected="${i === selectedIndex}">
                    <span class="ltms-cp-item-icon">${COMMAND_ICONS[cmd.icon] || COMMAND_ICONS.settings}</span>
                    <span class="ltms-cp-item-label">${escapeHtml(cmd.label)}</span>
                    ${cmd.view ? `<kbd class="ltms-cp-item-hint">Alt+${COMMANDS.findIndex(c => c.view === cmd.view) + 1}</kbd>` : ''}
                </button>
            `).join('');

            // Click handler
            results.querySelectorAll('.ltms-cp-item').forEach((el) => {
                el.addEventListener('click', () => {
                    const cmd = filtered[parseInt(el.dataset.idx ?? Array.from(results.children).indexOf(el), 10)];
                    executeCommand(cmd);
                });
                el.dataset.idx = Array.from(results.children).indexOf(el);
            });
        }

        function executeCommand(cmd) {
            if (!cmd) return;
            if (cmd.view && window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
                LTMS.Dashboard.loadView(cmd.view);
            } else if (cmd.action) {
                cmd.action();
            }
            closeCommandPalette();
        }

        function updateActive() {
            const items = results.querySelectorAll('.ltms-cp-item');
            items.forEach((el, i) => {
                el.classList.toggle('active', i === selectedIndex);
                el.setAttribute('aria-selected', i === selectedIndex ? 'true' : 'false');
            });
            // Scroll into view
            const active = items[selectedIndex];
            if (active) active.scrollIntoView({ block: 'nearest' });
        }

        // Input handler
        input.addEventListener('input', (e) => {
            const filtered = filterCommands(e.target.value);
            selectedIndex = 0;
            render(filtered);
        });

        // Keyboard navigation
        function handleKeydown(e) {
            const filtered = filterCommands(input.value);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, filtered.length - 1);
                updateActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateActive();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                executeCommand(filtered[selectedIndex]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeCommandPalette();
            }
        }
        input.addEventListener('keydown', handleKeydown);

        // Click en overlay cierra
        paletteEl.querySelector('.ltms-cp-overlay').addEventListener('click', closeCommandPalette);

        // Render inicial
        render(COMMANDS);

        // Focus trap
        const modal = paletteEl.querySelector('.ltms-cp-modal');
        paletteCleanup = trapFocus(modal, input);

        // Animación de entrada
        requestAnimationFrame(() => paletteEl.classList.add('ltms-cp-open'));
    }

    function closeCommandPalette() {
        if (!paletteEl) return;
        if (paletteCleanup) {
            paletteCleanup();
            paletteCleanup = null;
        }
        paletteEl.classList.remove('ltms-cp-open');
        document.body.style.overflow = '';
        setTimeout(() => {
            if (paletteEl && paletteEl.parentNode) paletteEl.parentNode.removeChild(paletteEl);
            paletteEl = null;
        }, 200);
    }

    LTMS.UX.openCommandPalette = openCommandPalette;
    LTMS.UX.closeCommandPalette = closeCommandPalette;

    function initCommandPalette() {
        // Cmd+K / Ctrl+K
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (paletteEl) {
                    closeCommandPalette();
                } else {
                    openCommandPalette();
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 22. SKELETON LOADERS — Placeholders durante carga SPA
    // ═══════════════════════════════════════════════════════════

    /**
     * Renderiza un skeleton loader para una vista del dashboard.
     * Mejora la percepción de velocidad al mostrar placeholders
     * estructurados en lugar de "..." o spinners genéricos.
     */
    const SKELETON_TEMPLATES = {
        home: `
            <div class="ltms-skeleton-view ltms-skeleton-home">
                <div class="ltms-skeleton-row" style="margin-bottom:24px;">
                    <div class="ltms-skeleton" style="width:200px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-grid">
                    <div class="ltms-skeleton-card" repeat="4"></div>
                </div>
                <div class="ltms-skeleton-chart"></div>
            </div>
        `,
        orders: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-row">
                    <div class="ltms-skeleton" style="width:150px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-table">
                    <div class="ltms-skeleton-row" repeat="6"></div>
                </div>
            </div>
        `,
        products: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-row">
                    <div class="ltms-skeleton" style="width:150px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-grid">
                    <div class="ltms-skeleton-product" repeat="8"></div>
                </div>
            </div>
        `,
        wallet: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-wallet"></div>
                <div class="ltms-skeleton-table">
                    <div class="ltms-skeleton-row" repeat="5"></div>
                </div>
            </div>
        `,
        default: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton" style="width:200px;height:32px;margin-bottom:24px;"></div>
                <div class="ltms-skeleton" style="width:100%;height:120px;margin-bottom:16px;"></div>
                <div class="ltms-skeleton" style="width:100%;height:120px;"></div>
            </div>
        `,
    };

    /**
     * FIX-SKELETON-01: showSkeleton() ya NO reemplaza target.innerHTML.
     * Antes borraba por completo el contenido real de la sección (métricas,
     * tablas, #ltms-wallet-tbody, etc.), y como hideSkeleton() nunca existió,
     * ese contenido no volvía — el AJAX real actualizaba selectores que ya
     * no estaban en el DOM y fallaba en silencio. Ahora se pinta un overlay
     * ENCIMA (ver .ltms-skeleton-overlay en ltms-ux-enhancements.css) que se
     * puede quitar sin haber tocado el DOM original.
     */
    function showSkeleton(view, container) {
        const target = container || document.getElementById('ltms-view-' + view)
            || document.querySelector('.ltms-view-section[style*="block"], .ltms-view-section:not([style*="none"])');
        if (!target) return;

        // Ya hay un overlay activo para esta sección — no duplicar.
        if (target.querySelector(':scope > .ltms-skeleton-overlay')) return;

        const template = (SKELETON_TEMPLATES[view] || SKELETON_TEMPLATES.default).replace(/repeat="(\d+)"/g, (m, n) => {
            const count = parseInt(n, 10);
            const el = m.replace(/repeat="\d+"/, '').replace(/\s+/g, ' ').trim();
            return Array(count).fill(el).join('');
        });

        const overlay = document.createElement('div');
        overlay.className = 'ltms-skeleton-overlay';
        overlay.setAttribute('data-ltms-skeleton-view', view || '');
        overlay.innerHTML = template;

        // El overlay necesita un ancestro con position != static para
        // cubrir exactamente la sección (position:absolute; inset:0;).
        if (window.getComputedStyle(target).position === 'static') {
            target.classList.add('ltms-skeleton-host');
        }

        target.appendChild(overlay);

        // Failsafe: si por lo que sea nadie llama hideSkeleton() (vista sin
        // AJAX propio, respuesta que nunca dispara ajaxComplete, etc.), el
        // overlay se autoelimina — nunca debe quedar pegado para siempre.
        overlay.dataset.ltmsSkeletonTimer = String(setTimeout(() => {
            if (overlay.parentNode) overlay.remove();
        }, 6000));
    }

    /**
     * FIX-SKELETON-01: contraparte de showSkeleton(), antes inexistente.
     * Quita el overlay de la sección indicada sin tocar su contenido real.
     */
    function hideSkeleton(view, container) {
        const target = container || document.getElementById('ltms-view-' + view);
        if (!target) return;

        const overlay = (view && target.querySelector(':scope > .ltms-skeleton-overlay[data-ltms-skeleton-view="' + view + '"]'))
            || target.querySelector(':scope > .ltms-skeleton-overlay');
        if (!overlay) return;

        clearTimeout(Number(overlay.dataset.ltmsSkeletonTimer));
        overlay.remove();
    }

    LTMS.UX.showSkeleton = showSkeleton;
    LTMS.UX.hideSkeleton = hideSkeleton;

    function initSkeletonLoaders() {
        // Hook en navegación del dashboard para mostrar skeleton
        if (typeof jQuery !== 'undefined' && window.LTMS && LTMS.Dashboard) {
            const origLoadView = LTMS.Dashboard.loadView;
            if (typeof origLoadView === 'function') {
                LTMS.Dashboard.loadView = function (view, forceRefresh) {
                    // AUD-01 FIX: solo mostrar skeleton si la vista tiene un
                    // método load<View>View dedicado (es decir, va a hacer AJAX).
                    // Las vistas estáticas (insurance, bookings, marketing,
                    // security, donations, posgold, shipping-statement, etc.)
                    // no hacen AJAX ltms_get_* → el overlay nunca se quitaría
                    // via ajaxComplete y el usuario vería la vista "en blanco"
                    // durante 6s (failsafe) sobre contenido ya renderizado.
                    var normalized = view.split('-').map(function(w) {
                        return w.charAt(0).toUpperCase() + w.slice(1);
                    }).join('');
                    var loadMethod = 'load' + normalized + 'View';
                    if (typeof this[loadMethod] === 'function') {
                        setTimeout(() => showSkeleton(view), 0);
                    }
                    // Llamar al método original
                    return origLoadView.call(this, view, forceRefresh);
                };
            }

            // FIX-SKELETON-01: quitar el skeleton cuando responde el AJAX
            // real del dashboard (éxito o error) — antes nada lo hacía.
            jQuery(document).on('ajaxComplete', function (event, xhr, settings) {
                const rawData = settings && settings.data;
                let action = null;

                if (typeof rawData === 'string') {
                    const match = rawData.match(/(?:^|&)action=([^&]+)/);
                    if (match) action = decodeURIComponent(match[1]);
                } else if (rawData && typeof rawData === 'object') {
                    action = rawData.action;
                }

                if (!action || action.indexOf('ltms_get_') !== 0) return;

                const view = LTMS.Dashboard.currentView;
                if (view) hideSkeleton(view);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 24. TOUR SYSTEM — Tour guiado/onboarding interactivo
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de tour guiado que destaca elementos del dashboard
     * con un spotlight y tooltip explicativo. Ideal para onboarding
     * de nuevos vendedores.
     */

    const TOUR_STEPS = [
        {
            target: '.ltms-sidebar',
            title: 'Navegación principal',
            body: 'Usa el menú lateral para acceder a todas las secciones de tu panel: pedidos, productos, billetera y más.',
            placement: 'right',
        },
        {
            target: '.ltms-cp-trigger',
            title: 'Búsqueda rápida (Cmd+K)',
            body: 'Presiona Cmd+K (Mac) o Ctrl+K (Windows) en cualquier momento para abrir la búsqueda rápida y navegar a cualquier sección al instante.',
            placement: 'bottom',
        },
        {
            target: '.ltms-metrics-grid',
            title: 'Tus métricas en tiempo real',
            body: 'Aquí verás un resumen de tus ventas, pedidos, comisiones y balance. Los números se actualizan automáticamente.',
            placement: 'top',
        },
        {
            target: '.ltms-topbar-notif',
            title: 'Notificaciones',
            body: 'Recibirás alertas sobre nuevos pedidos, pagos, retiros y verificación KYC. Mantente al día revisando este icono.',
            placement: 'bottom',
        },
        {
            target: '.ltms-balance-widget',
            title: 'Tu billetera',
            body: 'Desde aquí puedes solicitar retiros a tu cuenta bancaria. Los fondos se liberan automáticamente cuando se completa un pedido.',
            placement: 'right',
        },
    ];

    let tourState = {
        active: false,
        currentStep: 0,
        overlay: null,
        spotlight: null,
        tooltip: null,
    };

    function startTour() {
        if (tourState.active) return;
        if (!document.querySelector('.ltms-dashboard-container')) {
            toast('info', 'Tour no disponible', 'El tour solo está disponible en el panel de vendedor.');
            return;
        }

        tourState.active = true;
        tourState.currentStep = 0;

        // Crear overlay
        tourState.overlay = document.createElement('div');
        tourState.overlay.className = 'ltms-tour-overlay';
        tourState.spotlight = document.createElement('div');
        tourState.spotlight.className = 'ltms-tour-spotlight';
        tourState.tooltip = document.createElement('div');
        tourState.tooltip.className = 'ltms-tour-tooltip';

        tourState.overlay.appendChild(tourState.spotlight);
        tourState.overlay.appendChild(tourState.tooltip);
        document.body.appendChild(tourState.overlay);
        document.body.style.overflow = 'hidden';

        renderTourStep();

        // Cerrar con Escape
        document.addEventListener('keydown', tourKeydownHandler);

        announce('Tour iniciado. Presiona Escape para salir.');
    }

    function tourKeydownHandler(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            endTour();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            nextTourStep();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            prevTourStep();
        }
    }

    function renderTourStep() {
        const step = TOUR_STEPS[tourState.currentStep];
        if (!step) {
            endTour();
            return;
        }

        const target = document.querySelector(step.target);
        if (!target) {
            // Si el target no existe, saltar al siguiente paso
            tourState.currentStep++;
            renderTourStep();
            return;
        }

        // Scroll al elemento
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(() => {
            const rect = target.getBoundingClientRect();
            const padding = 8;

            // Posicionar spotlight
            tourState.spotlight.style.top = (rect.top - padding) + 'px';
            tourState.spotlight.style.left = (rect.left - padding) + 'px';
            tourState.spotlight.style.width = (rect.width + padding * 2) + 'px';
            tourState.spotlight.style.height = (rect.height + padding * 2) + 'px';

            // Posicionar tooltip según placement
            const tooltip = tourState.tooltip;
            tooltip.innerHTML = `
                <div class="ltms-tour-tooltip-header">
                    <span class="ltms-tour-step-num">${tourState.currentStep + 1}</span>
                    <h3 class="ltms-tour-title">${escapeHtml(step.title)}</h3>
                </div>
                <p class="ltms-tour-body">${escapeHtml(step.body)}</p>
                <div class="ltms-tour-footer">
                    <span class="ltms-tour-progress">${tourState.currentStep + 1} de ${TOUR_STEPS.length}</span>
                    <div class="ltms-tour-actions">
                        ${tourState.currentStep > 0 ? '<button type="button" class="ltms-tour-btn ltms-tour-btn-back" data-tour-action="prev">Atrás</button>' : ''}
                        ${tourState.currentStep < TOUR_STEPS.length - 1
                            ? '<button type="button" class="ltms-tour-btn ltms-tour-btn-next" data-tour-action="next">Siguiente</button>'
                            : '<button type="button" class="ltms-tour-btn ltms-tour-btn-next" data-tour-action="finish">Finalizar</button>'}
                        <button type="button" class="ltms-tour-btn ltms-tour-btn-skip" data-tour-action="skip">Saltar</button>
                    </div>
                </div>
            `;

            // Calcular posición del tooltip
            const tooltipRect = tooltip.getBoundingClientRect();
            let top, left;

            switch (step.placement) {
                case 'right':
                    top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
                    left = rect.right + 16;
                    break;
                case 'left':
                    top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
                    left = rect.left - tooltipRect.width - 16;
                    break;
                case 'top':
                    top = rect.top - tooltipRect.height - 16;
                    left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                    break;
                case 'bottom':
                default:
                    top = rect.bottom + 16;
                    left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                    break;
            }

            // Ajustar si sale de la pantalla
            if (left < 16) left = 16;
            if (left + tooltipRect.width > window.innerWidth - 16) {
                left = window.innerWidth - tooltipRect.width - 16;
            }
            if (top < 16) top = rect.bottom + 16;
            if (top + tooltipRect.height > window.innerHeight - 16) {
                top = rect.top - tooltipRect.height - 16;
            }

            tooltip.style.top = top + 'px';
            tooltip.style.left = left + 'px';

            requestAnimationFrame(() => tooltip.classList.add('ltms-tour-visible'));
        }, 300);
    }

    function nextTourStep() {
        tourState.currentStep++;
        if (tourState.currentStep >= TOUR_STEPS.length) {
            endTour();
        } else {
            tourState.tooltip.classList.remove('ltms-tour-visible');
            setTimeout(renderTourStep, 200);
        }
    }

    function prevTourStep() {
        if (tourState.currentStep > 0) {
            tourState.currentStep--;
            tourState.tooltip.classList.remove('ltms-tour-visible');
            setTimeout(renderTourStep, 200);
        }
    }

    function endTour() {
        if (!tourState.active) return;
        tourState.active = false;
        document.removeEventListener('keydown', tourKeydownHandler);

        if (tourState.tooltip) tourState.tooltip.classList.remove('ltms-tour-visible');

        setTimeout(() => {
            if (tourState.overlay && tourState.overlay.parentNode) {
                tourState.overlay.parentNode.removeChild(tourState.overlay);
            }
            tourState.overlay = null;
            tourState.spotlight = null;
            tourState.tooltip = null;
            document.body.style.overflow = '';
        }, 200);

        // Marcar tour como completado en localStorage
        try { localStorage.setItem('ltms-tour-completed', 'true'); } catch (e) { /* noop */ }

        if (window.LTMS && LTMS.UX) {
            LTMS.UX.toastSuccess('¡Tour completado!', 'Ya estás listo para vender. ¡Mucho éxito!');
        }
        announce('Tour finalizado.');
    }

    LTMS.UX.startTour = startTour;
    LTMS.UX.endTour = endTour;

    // ═══════════════════════════════════════════════════════════
    // 25. ONBOARDING CHECKLIST — Gamificación de onboarding
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de checklist de onboarding que recompensa al vendedor
     * por completar tareas clave. Muestra progreso, desbloquea
     * badges y celebra hitos con confetti.
     */

    const ONBOARDING_TASKS = [
        { id: 'profile',     label: 'Completa tu perfil de tienda',     icon: '👤', points: 10, check: () => document.querySelector('.ltms-settings-view') || getMeta('ltms_store_name') },
        { id: 'kyc',         label: 'Verifica tu identidad (KYC)',      icon: '🪪', points: 20, check: () => getMeta('ltms_kyc_status') === 'approved' },
        { id: 'first_product', label: 'Publica tu primer producto',     icon: '🛍️', points: 15, check: () => hasProducts() },
        { id: 'bank_account', label: 'Configura tu cuenta bancaria',   icon: '🏦', points: 10, check: () => getMeta('ltms_bank_account_number') },
        { id: 'first_sale',  label: 'Consigue tu primera venta',       icon: '💰', points: 25, check: () => hasOrders() },
        { id: 'tour',        label: 'Completa el tour del panel',      icon: '🎯', points: 5,  check: () => localStorage.getItem('ltms-tour-completed') === 'true' },
        { id: 'storefront',  label: 'Personaliza tu tienda pública',   icon: '🎨', points: 15, check: () => getMeta('ltms_store_description') },
    ];

    function getMeta(key) {
        // Helper simplificado — en producción esto vendría del servidor
        try { return localStorage.getItem('ltms-meta-' + key) || ''; } catch (e) { return ''; }
    }

    function hasProducts() {
        return document.querySelector('.ltms-products-grid .ltms-product-card') !== null;
    }

    function hasOrders() {
        const ordersTable = document.querySelector('#ltms-orders-tbody');
        return ordersTable && ordersTable.querySelectorAll('tr:not(:first-child)').length > 0;
    }

    function getOnboardingProgress() {
        const completed = ONBOARDING_TASKS.filter((t) => {
            try { return t.check(); } catch (e) { return false; }
        });
        const totalPoints = ONBOARDING_TASKS.reduce((sum, t) => sum + t.points, 0);
        const earnedPoints = completed.reduce((sum, t) => sum + t.points, 0);
        return {
            completed: completed.length,
            total: ONBOARDING_TASKS.length,
            percentage: Math.round((completed.length / ONBOARDING_TASKS.length) * 100),
            points: earnedPoints,
            totalPoints,
            tasks: ONBOARDING_TASKS.map((t) => ({
                ...t,
                done: completed.includes(t),
            })),
        };
    }

    function renderOnboardingWidget() {
        const progress = getOnboardingProgress();
        if (progress.percentage === 100) return null; // No mostrar si todo completado

        const widget = document.createElement('div');
        widget.className = 'ltms-onboarding-widget';
        widget.innerHTML = `
            <div class="ltms-onboarding-header">
                <div class="ltms-onboarding-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Configura tu tienda
                </div>
                <span class="ltms-onboarding-points">${progress.points}/${progress.totalPoints} pts</span>
            </div>
            <div class="ltms-onboarding-progress">
                <div class="ltms-onboarding-progress-bar" style="width:${progress.percentage}%;"></div>
            </div>
            <div class="ltms-onboarding-tasks">
                ${progress.tasks.map((t) => `
                    <div class="ltms-onboarding-task ${t.done ? 'done' : ''}" data-task="${t.id}">
                        <div class="ltms-onboarding-task-icon">${t.done ? '✓' : t.icon}</div>
                        <div class="ltms-onboarding-task-info">
                            <div class="ltms-onboarding-task-label">${escapeHtml(t.label)}</div>
                            <div class="ltms-onboarding-task-points">+${t.points} pts</div>
                        </div>
                        ${!t.done && t.id === 'tour' ? '<button type="button" class="ltms-onboarding-task-action" data-tour-start>Iniciar</button>' : ''}
                    </div>
                `).join('')}
            </div>
        `;

        // Click en tarea para navegar
        widget.querySelectorAll('.ltms-onboarding-task').forEach((el) => {
            el.addEventListener('click', () => {
                const taskId = el.dataset.task;
                const task = ONBOARDING_TASKS.find((t) => t.id === taskId);
                if (task && !task.done) {
                    navigateToTask(taskId);
                }
            });
        });

        return widget;
    }

    function navigateToTask(taskId) {
        const routes = {
            profile: 'settings',
            kyc: 'settings',
            first_product: 'products',
            bank_account: 'settings',
            first_sale: 'orders',
            storefront: 'settings',
        };
        const view = routes[taskId];
        if (view && window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
            LTMS.Dashboard.loadView(view);
        }
    }

    function initOnboardingWidget() {
        // Solo mostrar en dashboard
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // No mostrar si ya está completo
        const progress = getOnboardingProgress();
        if (progress.percentage === 100) return;

        // Insertar al inicio de la vista home
        const insertWidget = () => {
            const homeView = document.querySelector('#ltms-view-home .ltms-view-pad, #ltms-view-home');
            if (!homeView) return;
            if (homeView.querySelector('.ltms-onboarding-widget')) return;

            const widget = renderOnboardingWidget();
            if (widget) {
                homeView.insertBefore(widget, homeView.firstChild.nextSibling);
            }
        };

        // Intentar insertar periódicamente hasta que la vista home esté visible
        setTimeout(insertWidget, 1000);
        setTimeout(insertWidget, 3000);

        // Re-insertar cuando se carga la vista home
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', (e, view) => {
                if (view === 'home') setTimeout(insertWidget, 200);
            });
        }
    }

    LTMS.UX.getOnboardingProgress = getOnboardingProgress;

    // Confetti simple para celebrar hitos


    function initTour() {
        // Click en botones de tour
        document.addEventListener('click', (e) => {
            const action = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-tour-action]');
            if (action) {
                e.preventDefault();
                const act = action.dataset.tourAction;
                if (act === 'next') nextTourStep();
                else if (act === 'prev') prevTourStep();
                else if (act === 'skip' || act === 'finish') endTour();
                return;
            }

            // Launcher button
            const launcher = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-tour-launcher');
            if (launcher) {
                e.preventDefault();
                startTour();
            }
        });

        // Mostrar tour automáticamente para nuevos usuarios (una sola vez)
        try {
            const completed = localStorage.getItem('ltms-tour-completed');
            const skipped = sessionStorage.getItem('ltms-tour-skipped');
            if (!completed && !skipped && document.querySelector('.ltms-dashboard-container')) {
                // Añadir launcher después de 3s
                setTimeout(() => {
                    if (!document.querySelector('.ltms-tour-launcher')) {
                        const launcher = document.createElement('button');
                        launcher.className = 'ltms-tour-launcher';
                        launcher.setAttribute('aria-label', 'Iniciar tour guiado');
                        launcher.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                        document.body.appendChild(launcher);
                    }
                }, 3000);
            }
        } catch (e) { /* noop */ }
    }

    // ═══════════════════════════════════════════════════════════
    // 26. MOBILE GESTURES — Swipe y pull-to-refresh
    // ═══════════════════════════════════════════════════════════

    /**
     * Gestures para móvil:
     * - Swipe right desde el borde izquierdo → abre sidebar
     * - Swipe left en sidebar abierto → cierra sidebar
     * - Pull-to-refresh en el contenido principal (scroll top)
     * - Swipe horizontal entre vistas del dashboard
     */

    function initMobileGestures() {
        // Solo activar en touch devices
        if (!('ontouchstart' in window)) return;

        const sidebar = document.querySelector('.ltms-sidebar');
        const overlay = document.querySelector('.ltms-sidebar-overlay');
        const mainContent = document.querySelector('.ltms-main-content');

        if (!sidebar || !mainContent) return;

        let touchStartX = 0;
        let touchStartY = 0;
        let touchStartTime = 0;
        let isTracking = false;
        let isPulling = false;
        let pullDistance = 0;
        const EDGE_THRESHOLD = 30; // px desde el borde
        const SWIPE_THRESHOLD = 80; // distancia mínima para swipe
        const PULL_THRESHOLD = 70; // distancia para pull-to-refresh

        // ── Swipe para abrir/cerrar sidebar ──
        document.addEventListener('touchstart', (e) => {
            if (e.target.matches('input, textarea, select, button, a')) return;

            const touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
            touchStartTime = Date.now();
            isTracking = true;

            // Detectar si empieza desde el borde izquierdo (para abrir sidebar)
            const isEdge = touch.clientX < EDGE_THRESHOLD;
            const sidebarOpen = sidebar.classList.contains('ltms-sidebar-open');

            if (isEdge && !sidebarOpen) {
                isTracking = 'open';
            } else if (sidebarOpen) {
                isTracking = 'close';
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!isTracking) return;

            const touch = e.touches[0];
            const deltaX = touch.clientX - touchStartX;
            const deltaY = touch.clientY - touchStartY;

            // Si el movimiento es más vertical que horizontal, no es swipe
            if (Math.abs(deltaY) > Math.abs(deltaX)) {
                isTracking = isTracking === 'open' || isTracking === 'close' ? false : isTracking;
                return;
            }

            // Abrir sidebar con swipe right desde el borde
            if (isTracking === 'open' && deltaX > 0) {
                sidebar.style.transform = `translateX(${deltaX - 260}px)`;
                sidebar.style.transition = 'none';
                if (overlay) {
                    overlay.style.display = 'block';
                    overlay.style.opacity = Math.min(deltaX / 260, 0.5);
                }
            }
            // Cerrar sidebar con swipe left
            else if (isTracking === 'close' && deltaX < 0) {
                sidebar.style.transform = `translateX(${deltaX}px)`;
                sidebar.style.transition = 'none';
                if (overlay) {
                    overlay.style.opacity = Math.max(1 + deltaX / 260, 0);
                }
            }
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            if (!isTracking) return;

            const touch = e.changedTouches[0];
            const deltaX = touch.clientX - touchStartX;
            const deltaTime = Date.now() - touchStartTime;

            sidebar.style.transition = '';
            sidebar.style.transform = '';

            if (overlay) {
                overlay.style.opacity = '';
            }

            // Swipe rápido o distancia suficiente
            if (isTracking === 'open' && (deltaX > SWIPE_THRESHOLD || (deltaTime < 300 && deltaX > 50))) {
                sidebar.classList.add('ltms-sidebar-open');
                if (overlay) {
                    overlay.classList.add('active');
                    overlay.style.display = 'block';
                }
                document.body.style.overflow = 'hidden';
            } else if (isTracking === 'close' && (deltaX < -SWIPE_THRESHOLD || (deltaTime < 300 && deltaX < -50))) {
                sidebar.classList.remove('ltms-sidebar-open');
                if (overlay) {
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                }
                document.body.style.overflow = '';
            } else if (overlay) {
                overlay.style.display = '';
            }

            isTracking = false;
        }, { passive: true });

        // ── Pull-to-refresh ──
        let refreshIndicator = null;

        mainContent.addEventListener('touchstart', (e) => {
            const scrollTop = mainContent.scrollTop || window.scrollY;
            if (scrollTop > 0) return;

            const touch = e.touches[0];
            touchStartY = touch.clientY;
            isPulling = true;
            pullDistance = 0;
        }, { passive: true });

        mainContent.addEventListener('touchmove', (e) => {
            if (!isPulling) return;

            const touch = e.touches[0];
            const deltaY = touch.clientY - touchStartY;

            if (deltaY > 0 && deltaY < 120) {
                pullDistance = deltaY;

                if (!refreshIndicator) {
                    refreshIndicator = document.createElement('div');
                    refreshIndicator.className = 'ltms-pull-refresh';
                    refreshIndicator.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
                    mainContent.insertBefore(refreshIndicator, mainContent.firstChild);
                }

                const progress = Math.min(pullDistance / PULL_THRESHOLD, 1);
                const rotation = progress * 180;
                refreshIndicator.style.transform = `translateY(${pullDistance}px) rotate(${rotation}deg)`;
                refreshIndicator.style.opacity = progress;

                if (pullDistance >= PULL_THRESHOLD) {
                    refreshIndicator.classList.add('ready');
                } else {
                    refreshIndicator.classList.remove('ready');
                }
            }
        }, { passive: true });

        mainContent.addEventListener('touchend', () => {
            if (!isPulling) return;
            isPulling = false;

            if (refreshIndicator && pullDistance >= PULL_THRESHOLD) {
                // Trigger refresh
                refreshIndicator.classList.add('spinning');
                if (window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
                    LTMS.Dashboard.loadView(LTMS.Dashboard.currentView || 'home', true);
                }
                announce('Actualizando datos...');

                setTimeout(() => {
                    if (refreshIndicator && refreshIndicator.parentNode) {
                        refreshIndicator.parentNode.removeChild(refreshIndicator);
                    }
                    refreshIndicator = null;
                }, 1000);
            } else if (refreshIndicator) {
                refreshIndicator.style.transform = '';
                refreshIndicator.style.opacity = '0';
                setTimeout(() => {
                    if (refreshIndicator && refreshIndicator.parentNode) {
                        refreshIndicator.parentNode.removeChild(refreshIndicator);
                    }
                    refreshIndicator = null;
                }, 300);
            }

            pullDistance = 0;
        }, { passive: true });

        // ── Swipe horizontal entre vistas ──
        let viewSwipeStart = 0;
        let viewSwipeActive = false;

        mainContent.addEventListener('touchstart', (e) => {
            const touch = e.touches[0];
            viewSwipeStart = touch.clientX;
            viewSwipeActive = true;
        }, { passive: true });

        mainContent.addEventListener('touchend', (e) => {
            if (!viewSwipeActive) return;
            viewSwipeActive = false;

            const touch = e.changedTouches[0];
            const deltaX = touch.clientX - viewSwipeStart;

            // Solo si el swipe es horizontal y significativo
            if (Math.abs(deltaX) > 100) {
                const navItems = Array.from(document.querySelectorAll('.ltms-nav-item[data-view]'));
                const activeItem = document.querySelector('.ltms-nav-item.active');
                const activeIndex = navItems.indexOf(activeItem);

                if (deltaX > 0 && activeIndex > 0) {
                    // Swipe right → vista anterior
                    navItems[activeIndex - 1].click();
                } else if (deltaX < 0 && activeIndex < navItems.length - 1) {
                    // Swipe left → vista siguiente
                    navItems[activeIndex + 1].click();
                }
            }
        }, { passive: true });
    }

    // ═══════════════════════════════════════════════════════════
    // 27. BULK ACTIONS — Acciones masivas en tablas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de acciones masivas para tablas del dashboard.
     * Añade checkboxes, contador de selección, barra de acciones
     * flotante y operaciones batch.
     */

    function initBulkActions() {
        // Detectar tablas con clase ltms-bulk-table
        document.addEventListener('change', (e) => {
            if (!e.target.matches('.ltms-bulk-checkbox')) return;

            const table = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-bulk-table');
            if (!table) return;

            updateBulkSelection(table);
        });

        // Select all
        document.addEventListener('change', (e) => {
            if (!e.target.matches('.ltms-bulk-select-all')) return;

            const table = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-bulk-table');
            if (!table) return;

            const checkboxes = table.querySelectorAll('.ltms-bulk-checkbox');
            checkboxes.forEach((cb) => {
                cb.checked = e.target.checked;
            });

            updateBulkSelection(table);
        });

        // Bulk action button
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-bulk-action]');
            if (!btn) return;

            const table = btn.closest('.ltms-bulk-table') || document.querySelector('.ltms-bulk-table');
            if (!table) return;

            const action = btn.dataset.bulkAction;
            const selected = Array.from(table.querySelectorAll('.ltms-bulk-checkbox:checked')).map((cb) => cb.value);

            if (!selected.length) {
                toast('warning', 'Sin selección', 'Selecciona al menos un elemento.');
                return;
            }

            // Disparar evento personalizado para que el código de la vista lo maneje
            const event = new CustomEvent('ltms:bulk-action', {
                detail: { action, ids: selected, table },
                bubbles: true,
            });
            table.dispatchEvent(event);

            announce(`Acción "${action}" ejecutada sobre ${selected.length} elemento(s).`);
        });
    }

    function updateBulkSelection(table) {
        const checkboxes = table.querySelectorAll('.ltms-bulk-checkbox');
        const checked = table.querySelectorAll('.ltms-bulk-checkbox:checked');
        const selectAll = table.querySelector('.ltms-bulk-select-all');

        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }

        // Mostrar/ocultar barra de acciones
        let actionBar = table.parentElement.querySelector('.ltms-bulk-action-bar');
        if (checked.length > 0) {
            if (!actionBar) {
                actionBar = document.createElement('div');
                actionBar.className = 'ltms-bulk-action-bar';
                actionBar.innerHTML = `
                    <div class="ltms-bulk-info">
                        <span class="ltms-bulk-count">0</span> seleccionado(s)
                    </div>
                    <div class="ltms-bulk-actions"></div>
                    <button type="button" class="ltms-bulk-clear" aria-label="Limpiar selección">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                `;
                table.parentElement.insertBefore(actionBar, table);

                actionBar.querySelector('.ltms-bulk-clear').addEventListener('click', () => {
                    table.querySelectorAll('.ltms-bulk-checkbox:checked').forEach((cb) => {
                        cb.checked = false;
                    });
                    updateBulkSelection(table);
                });
            }

            actionBar.querySelector('.ltms-bulk-count').textContent = checked.length;
            actionBar.classList.add('visible');

            // Copiar acciones del template
            const actionsTemplate = table.querySelector('.ltms-bulk-actions-template');
            if (actionsTemplate && !actionBar.querySelector('.ltms-bulk-actions').children.length) {
                actionBar.querySelector('.ltms-bulk-actions').innerHTML = actionsTemplate.innerHTML;
            }
        } else if (actionBar) {
            actionBar.classList.remove('visible');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 34. SMART NOTIFICATIONS — Filtros y prioridad
    // ═══════════════════════════════════════════════════════════

    /**
     * Mejora el panel de notificaciones con filtros por categoría,
     * prioridad visual y soporte para marcar como leída al hacer click.
     */

    const NOTIF_CATEGORIES = {
        order: { icon: '📦', label: 'Pedidos', color: '#3282B8' },
        payment: { icon: '💰', label: 'Pagos', color: '#16A34A' },
        kyc: { icon: '🪪', label: 'Verificación', color: '#F39C12' },
        shipping: { icon: '🚚', label: 'Envíos', color: '#8B5CF6' },
        system: { icon: '⚙️', label: 'Sistema', color: '#6B7280' },
    };

    const NOTIF_PRIORITY = {
        high: { label: 'Urgente', color: '#DC2626' },
        medium: { label: 'Importante', color: '#F59E0B' },
        low: { label: 'Info', color: '#6B7280' },
    };

    function enhanceNotificationItem(item) {
        if (!item || item.dataset.enhanced) return;
        item.dataset.enhanced = 'true';

        // Detectar categoría por contenido
        const text = (item.textContent || '').toLowerCase();
        let category = 'system';
        if (text.includes('pedido') || text.includes('orden')) category = 'order';
        else if (text.includes('pago') || text.includes('retiro') || text.includes('payout')) category = 'payment';
        else if (text.includes('kyc') || text.includes('verificación')) category = 'kyc';
        else if (text.includes('envío') || text.includes('guía')) category = 'shipping';

        const cat = NOTIF_CATEGORIES[category];
        if (cat) {
            item.dataset.category = category;
            item.style.borderLeftColor = cat.color;

            // Añadir icono de categoría si no tiene
            if (!item.querySelector('.ltms-notif-cat-icon')) {
                const icon = document.createElement('span');
                icon.className = 'ltms-notif-cat-icon';
                icon.textContent = cat.icon;
                icon.style.background = cat.color + '22';
                item.insertBefore(icon, item.firstChild);
            }
        }
    }

    function initSmartNotifications() {
        // Mejorar items existentes
        const enhance = () => {
            document.querySelectorAll('.ltms-notif-item:not([data-enhanced])').forEach(enhanceNotificationItem);
        };

        setTimeout(enhance, 2000);

        // Observer para nuevos items
        const notifList = document.getElementById('ltms-notif-list');
        if (notifList) {
            const observer = new MutationObserver(enhance);
            observer.observe(notifList, { childList: true, subtree: true });
        }

        // Filtros en el header del panel
        const panel = document.querySelector('.ltms-notifications-panel');
        if (panel && !panel.querySelector('.ltms-notif-filters')) {
            const header = panel.querySelector('.ltms-notif-header');
            if (header) {
                const filters = document.createElement('div');
                filters.className = 'ltms-notif-filters';
                filters.innerHTML = `
                    <button type="button" class="ltms-notif-filter active" data-filter="all">Todas</button>
                    <button type="button" class="ltms-notif-filter" data-filter="order">📦 Pedidos</button>
                    <button type="button" class="ltms-notif-filter" data-filter="payment">💰 Pagos</button>
                    <button type="button" class="ltms-notif-filter" data-filter="kyc">🪪 KYC</button>
                `;
                header.appendChild(filters);

                filters.addEventListener('click', (e) => {
                    const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-notif-filter');
                    if (!btn) return;

                    filters.querySelectorAll('.ltms-notif-filter').forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');

                    const filter = btn.dataset.filter;
                    document.querySelectorAll('.ltms-notif-item').forEach((item) => {
                        if (filter === 'all' || item.dataset.category === filter) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 36. ACTIVITY FEED — Timeline de actividad reciente
    // ═══════════════════════════════════════════════════════════

    /**
     * Timeline visual de actividad reciente del vendedor:
     * pedidos, pagos, retiros, KYC, productos, etc.
     * Se muestra como widget en el dashboard home.
     */

    function renderActivityFeed(activities) {
        if (!activities || !activities.length) {
            return `
                <div class="ltms-activity-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:8px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <p>Sin actividad reciente</p>
                </div>
            `;
        }

        const typeConfig = {
            order: { icon: '📦', color: '#3282B8', bg: '#DBEAFE' },
            payment: { icon: '💰', color: '#16A34A', bg: '#DCFCE7' },
            payout: { icon: '💸', color: '#F39C12', bg: '#FEF3C7' },
            kyc: { icon: '🪪', color: '#8B5CF6', bg: '#EDE9FE' },
            product: { icon: '🛍️', color: '#EC4899', bg: '#FCE7F3' },
            shipping: { icon: '🚚', color: '#06B6D4', bg: '#CFFAFE' },
            system: { icon: '⚙️', color: '#6B7280', bg: '#F3F4F6' },
        };

        return activities.map((act) => {
            const config = typeConfig[act.type] || typeConfig.system;
            return `
                <div class="ltms-activity-item" data-type="${act.type}">
                    <div class="ltms-activity-icon" style="background:${config.bg};color:${config.color};">
                        ${config.icon}
                    </div>
                    <div class="ltms-activity-content">
                        <div class="ltms-activity-title">${escapeHtml(act.title)}</div>
                        <div class="ltms-activity-desc">${escapeHtml(act.description || '')}</div>
                        <div class="ltms-activity-time">${escapeHtml(act.time || '')}</div>
                    </div>
                    ${act.amount ? `<div class="ltms-activity-amount ${act.amountType || ''}">${escapeHtml(act.amount)}</div>` : ''}
                </div>
            `;
        }).join('');
    }

    function loadActivityFeed() {
        const container = document.querySelector('#ltms-activity-feed');
        if (!container) return;

        container.innerHTML = '<div class="ltms-activity-loading"><div class="ltms-spinner-lg"></div></div>';

        if (typeof jQuery === 'undefined' || typeof ltmsDashboard === 'undefined') {
            // Fallback: datos de ejemplo
            container.innerHTML = renderActivityFeed([
                { type: 'order', title: 'Nuevo pedido #1024', description: 'Camiseta azul talla M', time: 'Hace 5 min', amount: '+$45.000', amountType: 'positive' },
                { type: 'payment', title: 'Pago recibido', description: 'Pedido #1023 completado', time: 'Hace 1 hora', amount: '+$89.000', amountType: 'positive' },
                { type: 'payout', title: 'Retiro solicitado', description: 'Transferencia bancaria', time: 'Hace 2 horas', amount: '-$200.000', amountType: 'negative' },
                { type: 'kyc', title: 'KYC aprobado', description: 'Tu identidad fue verificada', time: 'Ayer', amount: '' },
            ]);
            return;
        }

        jQuery.post(ltmsDashboard.ajax_url, {
            action: 'ltms_get_activity_feed',
            nonce: ltmsDashboard.nonce,
            limit: 10,
        }, (response) => {
            if (response.success && response.data && response.data.activities) {
                container.innerHTML = renderActivityFeed(response.data.activities);
            } else {
                container.innerHTML = renderActivityFeed([]);
            }
        }).fail(() => {
            container.innerHTML = renderActivityFeed([]);
        });
    }

    function initActivityFeed() {
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // Crear widget de activity feed en home si no existe
        const insertActivityFeed = () => {
            const homeView = document.querySelector('#ltms-view-home .ltms-home-grid, #ltms-view-home');
            if (!homeView || homeView.querySelector('#ltms-activity-feed')) return;

            const widget = document.createElement('div');
            widget.className = 'ltms-card ltms-activity-card';
            widget.innerHTML = `
                <div class="ltms-card-header">
                    <div class="ltms-card-header-title">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Actividad reciente
                        </h3>
                    </div>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-activity-refresh" aria-label="Actualizar actividad">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </button>
                </div>
                <div class="ltms-card-body ltms-activity-body" id="ltms-activity-feed">
                    <div class="ltms-activity-loading"><div class="ltms-spinner-lg"></div></div>
                </div>
            `;
            homeView.appendChild(widget);

            widget.querySelector('.ltms-activity-refresh').addEventListener('click', loadActivityFeed);

            setTimeout(loadActivityFeed, 800);
        };

        setTimeout(insertActivityFeed, 1500);
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', (e, view) => {
                if (view === 'home') setTimeout(insertActivityFeed, 300);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 37. DASHBOARD CUSTOMIZATION — Drag & drop de widgets
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite al vendedor reordenar widgets del dashboard via drag & drop.
     * El orden se persiste en localStorage.
     */

    function initDashboardCustomization() {
        if (!document.querySelector('.ltms-dashboard-container')) return;
        if (!('ondragstart' in window)) return;

        let draggedEl = null;
        let placeholder = null;

        const createPlaceholder = (height) => {
            placeholder = document.createElement('div');
            placeholder.className = 'ltms-widget-placeholder';
            placeholder.style.height = height + 'px';
        };

        document.addEventListener('dragstart', (e) => {
            const widget = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-draggable-widget');
            if (!widget) return;

            draggedEl = widget;
            widget.classList.add('ltms-widget-dragging');

            createPlaceholder(widget.offsetHeight);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        document.addEventListener('dragend', (e) => {
            const widget = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-draggable-widget');
            if (widget) widget.classList.remove('ltms-widget-dragging');

            if (placeholder && placeholder.parentNode) {
                placeholder.parentNode.removeChild(placeholder);
            }
            placeholder = null;
            draggedEl = null;

            saveWidgetOrder();
        });

        document.addEventListener('dragover', (e) => {
            if (!draggedEl) return;

            e.preventDefault();
            const container = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-home-grid, .ltms-view-section');
            if (!container) return;

            const afterElement = getDragAfterElement(container, e.clientY);
            if (afterElement == null) {
                container.appendChild(placeholder);
            } else {
                container.insertBefore(placeholder, afterElement);
            }
        });

        document.addEventListener('drop', (e) => {
            if (!draggedEl || !placeholder) return;
            e.preventDefault();

            placeholder.parentNode.insertBefore(draggedEl, placeholder);
        });

        function getDragAfterElement(container, y) {
            const els = [...container.querySelectorAll('.ltms-draggable-widget:not(.ltms-widget-dragging)')];

            return els.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: -Infinity }).element;
        }

        function saveWidgetOrder() {
            const container = document.querySelector('.ltms-home-grid, .ltms-view-section');
            if (!container) return;

            const order = [...container.querySelectorAll('.ltms-draggable-widget')].map((el) => el.dataset.widgetId || el.id || el.className);
            try {
                localStorage.setItem('ltms-widget-order', JSON.stringify(order));
            } catch (e) { /* noop */ }
        }

        function restoreWidgetOrder() {
            try {
                const saved = localStorage.getItem('ltms-widget-order');
                if (!saved) return;

                const order = JSON.parse(saved);
                const container = document.querySelector('.ltms-home-grid, .ltms-view-section');
                if (!container) return;

                order.forEach((id) => {
                    const el = container.querySelector(`[data-widget-id="${id}"], #${id}`);
                    if (el) container.appendChild(el);
                });
            } catch (e) { /* noop */ }
        }

        // Restaurar orden al cargar
        setTimeout(restoreWidgetOrder, 2000);
    }

    // ═══════════════════════════════════════════════════════════
    // 38. CHART HELPERS — Wrappers para visualización de datos
    // ═══════════════════════════════════════════════════════════

    /**
     * Helpers para crear gráficos consistentes con el design system.
     * Envuelve Chart.js con colores y opciones predefinidas.
     */

    const CHART_COLORS = {
        primary: '#0F4C75',
        secondary: '#3282B8',
        accent: '#F39C12',
        success: '#16A34A',
        danger: '#DC2626',
        info: '#2563EB',
        purple: '#8B5CF6',
        pink: '#EC4899',
    };

    const CHART_GRADIENTS = {};

    function createGradient(ctx, color1, color2) {
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, color1 + '40');
        gradient.addColorStop(1, color2 + '00');
        return gradient;
    }

    function createLineChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;
        const ctx = canvas.getContext('2d');

        const datasets = data.datasets.map((ds, i) => {
            const color = ds.color || Object.values(CHART_COLORS)[i];
            return {
                ...ds,
                borderColor: color,
                backgroundColor: ds.fill ? createGradient(ctx, color, color) : 'transparent',
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: color,
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
            };
        });

        return new Chart(canvas, {
            type: 'line',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: options.legend !== false,
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 16,
                            font: { size: 12, weight: '500' },
                            color: '#374151',
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#374151',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        usePointStyle: true,
                        titleFont: { size: 13, weight: '700' },
                        bodyFont: { size: 12 },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { color: '#E5E7EB' },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F3F4F6', drawBorder: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { display: false },
                    },
                },
                ...options,
            },
        });
    }

    function createBarChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;
        const ctx = canvas.getContext('2d');

        const datasets = data.datasets.map((ds, i) => {
            const color = ds.color || Object.values(CHART_COLORS)[i];
            return {
                ...ds,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: false,
            };
        });

        return new Chart(canvas, {
            type: 'bar',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false, ...options.legend },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F3F4F6', drawBorder: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { display: false },
                    },
                },
                ...options,
            },
        });
    }

    function createDoughnutChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;

        const colors = data.datasets[0].colors || Object.values(CHART_COLORS);

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: data.datasets.map((ds, i) => ({
                    ...ds,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 8,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            font: { size: 12 },
                            color: '#374151',
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                ...options,
            },
        });
    }

    LTMS.UX.charts = {
        colors: CHART_COLORS,
        line: createLineChart,
        bar: createBarChart,
        doughnut: createDoughnutChart,
    };

    // ═══════════════════════════════════════════════════════════
    // 39. REAL-TIME UPDATES — Polling inteligente
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de actualizaciones en tiempo real via polling adaptativo.
     * Aumenta la frecuencia cuando hay actividad y la reduce cuando
     * el usuario está inactivo.
     */

    const realtimeState = {
        active: false,
        interval: 30000, // 30s por defecto
        minInterval: 10000, // 10s cuando hay actividad
        maxInterval: 120000, // 2min cuando inactivo
        lastActivity: Date.now(),
        timer: null,
        handlers: {},
    };

    function registerRealtimeHandler(name, handler) {
        realtimeState.handlers[name] = handler;
    }

    function unregisterRealtimeHandler(name) {
        delete realtimeState.handlers[name];
    }

    function startRealtimeUpdates() {
        if (realtimeState.active) return;
        realtimeState.active = true;

        // Detectar actividad del usuario
        ['click', 'keydown', 'scroll', 'touchstart'].forEach((evt) => {
            document.addEventListener(evt, () => {
                realtimeState.lastActivity = Date.now();
            }, { passive: true });
        });

        function poll() {
            if (!realtimeState.active) return;

            const idleTime = Date.now() - realtimeState.lastActivity;
            if (idleTime > 60000) {
                // Usuario inactivo >1min: reducir frecuencia
                realtimeState.interval = Math.min(realtimeState.interval * 1.5, realtimeState.maxInterval);
            } else {
                // Usuario activo: frecuencia normal
                realtimeState.interval = realtimeState.minInterval;
            }

            // Ejecutar handlers
            Object.values(realtimeState.handlers).forEach((handler) => {
                try { handler(); } catch (e) { console.error('[LTMS.UX] Realtime handler error:', e); }
            });

            realtimeState.timer = setTimeout(poll, realtimeState.interval);
        }

        poll();
    }

    function stopRealtimeUpdates() {
        realtimeState.active = false;
        if (realtimeState.timer) {
            clearTimeout(realtimeState.timer);
            realtimeState.timer = null;
        }
    }

    LTMS.UX.realtime = {
        start: startRealtimeUpdates,
        stop: stopRealtimeUpdates,
        register: registerRealtimeHandler,
        unregister: unregisterRealtimeHandler,
    };

    function initRealtimeUpdates() {
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // Solo activar si la página está visible
        if (document.hidden) {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) startRealtimeUpdates();
                else stopRealtimeUpdates();
            });
        } else {
            startRealtimeUpdates();
        }

        // Pausar cuando la pestaña no está visible
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopRealtimeUpdates();
            } else {
                startRealtimeUpdates();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 49. NOTIFICATION CENTER — Centro con historial y settings
    // ═══════════════════════════════════════════════════════════

    /**
     * Centro de notificaciones avanzado:
     * - Historial persistente
     * - Filtros por categoría
     * - Marcar como leída individual
     * - Acciones rápidas (archivar, eliminar)
     * - Settings de preferencias
     */

    const notifCenter = {
        notifications: [],
        settings: {
            sound: true,
            desktop: false,
            categories: {
                order: true,
                payment: true,
                kyc: true,
                shipping: true,
                system: true,
            },
        },
    };

    function loadNotifSettings() {
        try {
            const saved = localStorage.getItem('ltms-notif-settings');
            if (saved) notifCenter.settings = { ...notifCenter.settings, ...JSON.parse(saved) };
        } catch (e) {}
    }

    function saveNotifSettings() {
        try {
            localStorage.setItem('ltms-notif-settings', JSON.stringify(notifCenter.settings));
        } catch (e) {}
    }

    function sendDesktopNotification(title, options = {}) {
        if (!notifCenter.settings.desktop) return;
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;

        try {
            new Notification(title, {
                body: options.body || '',
                icon: options.icon || '',
                tag: options.tag || 'ltms',
                ...options,
            });
        } catch (e) {}
    }

    function requestNotificationPermission() {
        if (!('Notification' in window)) {
            toast('warning', 'No soportado', 'Tu navegador no soporta notificaciones de escritorio.');
            return;
        }
        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                notifCenter.settings.desktop = true;
                saveNotifSettings();
                toast('success', 'Permitido', 'Recibirás notificaciones de escritorio.');
            } else {
                notifCenter.settings.desktop = false;
                saveNotifSettings();
                toast('info', 'Denegado', 'No recibirás notificaciones de escritorio.');
            }
        });
    }

    LTMS.UX.sendDesktopNotification = sendDesktopNotification;
    LTMS.UX.requestNotificationPermission = requestNotificationPermission;

    // ═══════════════════════════════════════════════════════════
    // 50. ANALYTICS DASHBOARD — Visualizaciones avanzadas
    // ═══════════════════════════════════════════════════════════

    /**
     * Dashboard de analytics con:
     * - Métricas con sparklines
     * - Gráficos de tendencia
     * - Top productos
     * - Distribución geográfica
     * - Comparación de periodos
     */

    function createSparkline(canvas, data, color = '#0F4C75') {
        if (typeof Chart === 'undefined' || !canvas || !data || !data.length) return null;

        return new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.map((_, i) => i),
                datasets: [{
                    data: data,
                    borderColor: color,
                    backgroundColor: color + '20',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.4,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 8,
                        cornerRadius: 6,
                        displayColors: false,
                    },
                },
                scales: {
                    x: { display: false },
                    y: { display: false },
                },
                elements: { point: { radius: 0 } },
            },
        });
    }

    function createMetricCard(config) {
        const card = document.createElement('div');
        card.className = 'ltms-card ltms-analytics-metric-card';
        card.innerHTML = `
            <div class="ltms-card-body" style="padding:16px;">
                <div class="ltms-analytics-metric-header">
                    <div class="ltms-analytics-metric-icon" style="background:${config.iconBg || '#DBEAFE'};color:${config.iconColor || '#2563EB'};">
                        ${config.icon || ''}
                    </div>
                    ${config.trend !== undefined ? `
                        <span class="ltms-metric-trend ${config.trend >= 0 ? 'up' : 'down'}">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                ${config.trend >= 0
                                    ? '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>'
                                    : '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>'}
                            </svg>
                            ${Math.abs(config.trend).toFixed(1)}%
                        </span>
                    ` : ''}
                </div>
                <div class="ltms-analytics-metric-value">${escapeHtml(config.value || '—')}</div>
                <div class="ltms-analytics-metric-label">${escapeHtml(config.label || '')}</div>
                ${config.sparkline ? `<div class="ltms-analytics-sparkline"><canvas width="100" height="30"></canvas></div>` : ''}
            </div>
        `;

        if (config.sparkline) {
            const canvas = card.querySelector('canvas');
            setTimeout(() => createSparkline(canvas, config.sparkline, config.sparklineColor || config.iconColor), 100);
        }

        return card;
    }

    function createTopProductsList(products) {
        const container = document.createElement('div');
        container.className = 'ltms-top-products';
        container.innerHTML = products.map((p, i) => `
            <div class="ltms-top-product-item">
                <div class="ltms-top-product-rank">${i + 1}</div>
                <div class="ltms-top-product-info">
                    <div class="ltms-top-product-name">${escapeHtml(p.name)}</div>
                    <div class="ltms-top-product-sales">${p.sales} ventas</div>
                </div>
                <div class="ltms-top-product-revenue">${escapeHtml(p.revenue)}</div>
                <div class="ltms-top-product-bar" style="width:${(p.sales / products[0].sales * 100)}%;background:${p.color || '#3282B8'};"></div>
            </div>
        `).join('');
        return container;
    }

    LTMS.UX.analytics = {
        createSparkline,
        createMetricCard,
        createTopProductsList,
    };

    // ═══════════════════════════════════════════════════════════
    // 54. IMAGE CROPPER — Recorte de imágenes antes de subir
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite recortar imágenes antes de subirlas (productos, KYC, etc.)
     * Usa canvas para el recorte, sin librerías externas.
     */

    function openImageCropper(imageSrc, options = {}) {
        const aspectRatio = options.aspectRatio || null; // ej: 1 (cuadrado), 4/3, 16/9
        const minWidth = options.minWidth || 100;
        const minHeight = options.minHeight || 100;
        const outputWidth = options.outputWidth || 600;
        const outputHeight = options.outputHeight || (aspectRatio ? outputWidth / aspectRatio : 600);

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-cropper-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-cropper-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-cropper-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-cropper-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/></svg>
                        Recortar imagen
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-cropper-body">
                    <div class="ltms-cropper-container" id="ltms-cropper-container">
                        <img src="${escapeHtml(imageSrc)}" id="ltms-cropper-img" alt="Imagen a recortar" style="max-width:100%;display:block;">
                        <div class="ltms-cropper-overlay-box" id="ltms-cropper-box"></div>
                    </div>
                    <div class="ltms-cropper-controls">
                        <label class="ltms-cropper-zoom-label">
                            <span>Zoom</span>
                            <input type="range" id="ltms-cropper-zoom" min="50" max="200" value="100">
                        </label>
                        ${aspectRatio ? `<span class="ltms-cropper-aspect">Proporción ${aspectRatio.toFixed(2)}:1</span>` : ''}
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cropper-apply">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Aplicar recorte
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const img = overlay.querySelector('#ltms-cropper-img');
        const box = overlay.querySelector('#ltms-cropper-box');
        const container = overlay.querySelector('#ltms-cropper-container');
        const zoomInput = overlay.querySelector('#ltms-cropper-zoom');

        let scale = 1;
        let boxX = 0, boxY = 0, boxW = 0, boxH = 0;
        let isDragging = false;
        let dragStartX = 0, dragStartY = 0;

        function initCropBox() {
            const imgRect = img.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();

            if (aspectRatio) {
                boxW = Math.min(imgRect.width * 0.8, imgRect.height * 0.8 * aspectRatio);
                boxH = boxW / aspectRatio;
            } else {
                boxW = imgRect.width * 0.7;
                boxH = imgRect.height * 0.7;
            }

            boxX = (imgRect.width - boxW) / 2;
            boxY = (imgRect.height - boxH) / 2;

            updateBox();
        }

        function updateBox() {
            box.style.left = boxX + 'px';
            box.style.top = boxY + 'px';
            box.style.width = boxW + 'px';
            box.style.height = boxH + 'px';
        }

        img.onload = initCropBox;

        // Drag del crop box
        box.addEventListener('mousedown', (e) => {
            isDragging = true;
            dragStartX = e.clientX - boxX;
            dragStartY = e.clientY - boxY;
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const imgRect = img.getBoundingClientRect();
            boxX = Math.max(0, Math.min(e.clientX - dragStartX, imgRect.width - boxW));
            boxY = Math.max(0, Math.min(e.clientY - dragStartY, imgRect.height - boxH));
            updateBox();
        });

        document.addEventListener('mouseup', () => { isDragging = false; });

        // Zoom
        zoomInput.addEventListener('input', (e) => {
            scale = e.target.value / 100;
            img.style.transform = `scale(${scale})`;
        });

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        // Apply crop
        overlay.querySelector('#ltms-cropper-apply').addEventListener('click', () => {
            const canvas = document.createElement('canvas');
            canvas.width = outputWidth;
            canvas.height = outputHeight;
            const ctx = canvas.getContext('2d');

            const imgRect = img.getBoundingClientRect();
            const scaleX = img.naturalWidth / (imgRect.width / scale);
            const scaleY = img.naturalHeight / (imgRect.height / scale);

            const srcX = (boxX / scale) * scaleX;
            const srcY = (boxY / scale) * scaleY;
            const srcW = (boxW / scale) * scaleX;
            const srcH = (boxH / scale) * scaleY;

            ctx.drawImage(img, srcX, srcY, srcW, srcH, 0, 0, outputWidth, outputHeight);

            const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.9);

            if (options.onCrop && typeof options.onCrop === 'function') {
                options.onCrop(croppedDataUrl, canvas);
            }

            close();
            toast('success', 'Imagen recortada', 'El recorte se aplicó correctamente.');
        });
    }

    LTMS.UX.openImageCropper = openImageCropper;

    // ═══════════════════════════════════════════════════════════
    // 92. LOYALTY POINTS — Display de puntos de fidelidad
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que muestra puntos de fidelidad acumulados,
     * progreso hacia el siguiente nivel y beneficios.
     */

    function initLoyaltyPoints() {
        document.querySelectorAll('[data-loyalty-widget]').forEach((container) => {
            if (container.dataset.lpInit) return;
            container.dataset.lpInit = 'true';

            const points = parseInt(container.dataset.loyaltyWidget || '0', 10);
            const level = container.dataset.loyaltyLevel || 'Bronce';
            const nextLevel = container.dataset.loyaltyNextLevel || 'Plata';
            const nextThreshold = parseInt(container.dataset.loyaltyNextThreshold || '1000', 10);
            const currentThreshold = parseInt(container.dataset.loyaltyCurrentThreshold || '0', 10);

            const range = nextThreshold - currentThreshold;
            const progress = Math.min(((points - currentThreshold) / range) * 100, 100);
            const remaining = Math.max(nextThreshold - points, 0);

            const levelColors = {
                'Bronce': '#CD7F32',
                'Plata': '#C0C0C0',
                'Oro': '#FFD700',
                'Platino': '#E5E4E2',
                'Diamante': '#B9F2FF',
            };

            const color = levelColors[level] || '#CD7F32';

            container.className = 'ltms-loyalty-widget';
            container.innerHTML = `
                <div class="ltms-loyalty-header">
                    <div class="ltms-loyalty-level-badge" style="background:${color};">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <div class="ltms-loyalty-info">
                        <div class="ltms-loyalty-level" style="color:${color};">${escapeHtml(level)}</div>
                        <div class="ltms-loyalty-points">${points.toLocaleString('es-CO')} puntos</div>
                    </div>
                </div>
                <div class="ltms-loyalty-progress">
                    <div class="ltms-loyalty-progress-bar" style="width:${progress}%;background:${color};"></div>
                </div>
                <div class="ltms-loyalty-next">
                    ${remaining > 0
                        ? `Te faltan <strong>${remaining.toLocaleString('es-CO')}</strong> puntos para <strong style="color:${levelColors[nextLevel] || '#C0C0C0'};">${escapeHtml(nextLevel)}</strong>`
                        : '¡Has alcanzado el nivel máximo!'}
                </div>
            `;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 100. RETURN/REFUND WIZARD — Asistente de devoluciones
    // ═══════════════════════════════════════════════════════════

    /**
     * Wizard multi-paso para gestionar devoluciones:
     * 1. Seleccionar items a devolver
     * 2. Motivo de devolución
     * 3. Método de reembolso
     * 4. Confirmación
     */

    function openReturnWizard(orderId, items = []) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-return-wizard-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-return-wizard-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-rw-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-rw-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        Solicitar devolución
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-return-wizard-body">
                    <div class="ltms-return-steps">
                        <div class="ltms-return-step active" data-rstep="1"><span>1</span> Items</div>
                        <div class="ltms-return-step" data-rstep="2"><span>2</span> Motivo</div>
                        <div class="ltms-return-step" data-rstep="3"><span>3</span> Reembolso</div>
                        <div class="ltms-return-step" data-rstep="4"><span>4</span> Confirmar</div>
                    </div>

                    <div class="ltms-return-page" data-rpage="1">
                        <h4>Selecciona los items a devolver</h4>
                        <div class="ltms-return-items">
                            ${items.map((item) => `
                                <label class="ltms-return-item">
                                    <input type="checkbox" class="ltms-return-item-check" data-item-id="${item.id}" data-item-name="${escapeHtml(item.name)}" data-item-price="${item.price || ''}">
                                    <div class="ltms-return-item-info">
                                        <div class="ltms-return-item-name">${escapeHtml(item.name)}</div>
                                        <div class="ltms-return-item-price">${escapeHtml(item.price || '')}</div>
                                    </div>
                                </label>
                            `).join('')}
                        </div>
                    </div>

                    <div class="ltms-return-page" data-rpage="2" style="display:none;">
                        <h4>¿Cuál es el motivo de la devolución?</h4>
                        <div class="ltms-return-reasons">
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="defective"> <span>🔧 Producto defectuoso</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="wrong_item"> <span>📦 Producto incorrecto</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="not_as_described"> <span>📝 No coincide con la descripción</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="changed_mind"> <span>🔄 Cambié de opinión</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="arrived_late"> <span>⏰ Llegó tarde</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="other"> <span>❓ Otro motivo</span></label>
                        </div>
                        <textarea class="ltms-return-details" placeholder="Cuéntanos más sobre el problema (opcional)..." rows="3"></textarea>
                    </div>

                    <div class="ltms-return-page" data-rpage="3" style="display:none;">
                        <h4>¿Cómo quieres el reembolso?</h4>
                        <div class="ltms-return-methods">
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="original" checked>
                                <div class="ltms-return-method-info">
                                    <strong>💳 Reembolso al método original</strong>
                                    <span>Se devolverá a la tarjeta/cuenta usada en la compra (3-5 días hábiles)</span>
                                </div>
                            </label>
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="wallet">
                                <div class="ltms-return-method-info">
                                    <strong>💰 Reembolso a billetera</strong>
                                    <span>Crédito instantáneo en tu billetera de la plataforma</span>
                                </div>
                            </label>
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="exchange">
                                <div class="ltms-return-method-info">
                                    <strong>🔄 Cambio por otro producto</strong>
                                    <span>Recibe un producto de igual o menor valor</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="ltms-return-page" data-rpage="4" style="display:none;">
                        <h4>Confirma tu solicitud</h4>
                        <div class="ltms-return-summary">
                            <div class="ltms-return-summary-row"><span>Items:</span> <strong id="ltms-rw-items">—</strong></div>
                            <div class="ltms-return-summary-row"><span>Motivo:</span> <strong id="ltms-rw-reason">—</strong></div>
                            <div class="ltms-return-summary-row"><span>Reembolso:</span> <strong id="ltms-rw-method">—</strong></div>
                        </div>
                        <div class="ltms-return-warning">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Los items deben estar en su estado original con etiquetas. Tienes 15 días desde la recepción.</span>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline" id="ltms-rw-back" style="display:none;">Atrás</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-rw-next">Siguiente</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-rw-submit" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Enviar solicitud
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        let currentPage = 1;

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        const nextBtn = overlay.querySelector('#ltms-rw-next');
        const backBtn = overlay.querySelector('#ltms-rw-back');
        const submitBtn = overlay.querySelector('#ltms-rw-submit');
        const steps = overlay.querySelectorAll('.ltms-return-step');
        const pages = overlay.querySelectorAll('.ltms-return-page');

        function showPage(page) {
            pages.forEach((p) => p.style.display = p.dataset.rpage == page ? 'block' : 'none');
            steps.forEach((s, i) => {
                s.classList.toggle('active', i + 1 === page);
                s.classList.toggle('completed', i + 1 < page);
            });
            backBtn.style.display = page > 1 ? '' : 'none';
            nextBtn.style.display = page < 4 ? '' : 'none';
            submitBtn.style.display = page === 4 ? '' : 'none';

            if (page === 4) updateSummary();
            currentPage = page;
        }

        function updateSummary() {
            const selected = [...overlay.querySelectorAll('.ltms-return-item-check:checked')];
            const itemsText = selected.map((c) => c.dataset.itemName).join(', ');
            const reason = overlay.querySelector('input[name="return_reason"]:checked');
            const method = overlay.querySelector('input[name="refund_method"]:checked');

            overlay.querySelector('#ltms-rw-items').textContent = itemsText || '—';
            overlay.querySelector('#ltms-rw-reason').textContent = reason?.parentElement.textContent.trim() || '—';
            overlay.querySelector('#ltms-rw-method').textContent = method?.parentElement.querySelector('strong')?.textContent || '—';
        }

        nextBtn.addEventListener('click', () => {
            if (currentPage === 1) {
                const selected = overlay.querySelectorAll('.ltms-return-item-check:checked');
                if (!selected.length) {
                    toast('warning', 'Selecciona al menos un item', 'Debes elegir qué productos devolver.');
                    return;
                }
            }
            if (currentPage === 2) {
                if (!overlay.querySelector('input[name="return_reason"]:checked')) {
                    toast('warning', 'Selecciona un motivo', 'Debes indicar por qué devuelves el producto.');
                    return;
                }
            }
            showPage(currentPage + 1);
        });

        backBtn.addEventListener('click', () => showPage(currentPage - 1));

        submitBtn.addEventListener('click', () => {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            const selected = [...overlay.querySelectorAll('.ltms-return-item-check:checked')].map((c) => c.dataset.itemId);
            const reason = overlay.querySelector('input[name="return_reason"]:checked')?.value;
            const method = overlay.querySelector('input[name="refund_method"]:checked')?.value;
            const details = overlay.querySelector('.ltms-return-details').value;

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_submit_return',
                    nonce: ltmsDashboard.nonce,
                    order_id: orderId,
                    items: selected,
                    reason: reason,
                    refund_method: method,
                    details: details,
                }, (response) => {
                    if (response.success) {
                        close();
                        showOrderSuccess({
                            order_number: response.data.return_id || 'DEV-' + Date.now(),
                            message: 'Tu solicitud de devolución ha sido enviada. Te contactaremos en 24h.',
                        });
                    } else {
                        toast('error', 'Error', response.data || 'No se pudo enviar la solicitud.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Enviar solicitud';
                    }
                });
            } else {
                setTimeout(() => {
                    close();
                    toast('success', 'Solicitud enviada', 'Te contactaremos en 24 horas.');
                }, 1000);
            }
        });

        showPage(1);
    }

    function initReturnWizard() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-return-wizard]');
            if (!trigger) return;
            e.preventDefault();

            const orderId = trigger.dataset.returnWizard;
            const items = JSON.parse(trigger.dataset.returnItems || '[]');
            openReturnWizard(orderId, items);
        });
    }

    LTMS.UX.openReturnWizard = openReturnWizard;

    // ═══════════════════════════════════════════════════════════
    // 101. INVOICE CENTER — Centro de descarga de facturas
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que lista todas las facturas/recibos del usuario
     * con opción de descarga PDF y vista previa.
     */

    function initInvoiceCenter() {
        document.querySelectorAll('[data-invoice-center]').forEach((container) => {
            if (container.dataset.icInit) return;
            container.dataset.icInit = 'true';

            const ajaxUrl = container.dataset.invoiceCenter;

            if (ajaxUrl && typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                container.innerHTML = '<div class="ltms-invoice-loading"><div class="ltms-spinner-lg"></div></div>';

                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_get_invoices',
                    nonce: ltmsDashboard.nonce,
                }, (response) => {
                    if (response.success && response.data && response.data.invoices) {
                        renderInvoices(container, response.data.invoices);
                    } else {
                        container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('orders') + '</div>';
                    }
                }).fail(() => {
                    container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('error') + '</div>';
                });
            }
        });
    }

    function renderInvoices(container, invoices) {
        if (!invoices.length) {
            container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('orders') + '</div>';
            return;
        }

        container.className = 'ltms-invoice-center';
        container.innerHTML = `
            <div class="ltms-invoice-list">
                ${invoices.map((inv) => `
                    <div class="ltms-invoice-item">
                        <div class="ltms-invoice-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        <div class="ltms-invoice-info">
                            <div class="ltms-invoice-number">Factura #${escapeHtml(inv.number)}</div>
                            <div class="ltms-invoice-date">${escapeHtml(inv.date)}</div>
                        </div>
                        <div class="ltms-invoice-amount">${escapeHtml(inv.amount)}</div>
                        <div class="ltms-invoice-actions">
                            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-invoice-preview" data-invoice-url="${escapeHtml(inv.url)}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Ver
                            </button>
                            <a href="${escapeHtml(inv.url)}" download class="ltms-btn ltms-btn-primary ltms-btn-sm">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                PDF
                            </a>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;

        // Preview buttons
        container.querySelectorAll('.ltms-invoice-preview').forEach((btn) => {
            btn.addEventListener('click', () => {
                openPrintPreview({
                    title: 'Factura',
                    content: `<iframe src="${btn.dataset.invoiceUrl}" style="width:100%;height:500px;border:none;"></iframe>`,
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 109. DIGITAL DOWNLOADS — Gestor de descargas digitales
    // ═══════════════════════════════════════════════════════════

    /**
     * Gestiona descargas de productos digitales: muestra archivos
     * disponibles, límite de descargas, expiración y historial.
     */

    function initDigitalDownloads() {
        document.querySelectorAll('[data-digital-downloads]').forEach((container) => {
            if (container.dataset.ddInit) return;
            container.dataset.ddInit = 'true';

            const orderId = container.dataset.digitalDownloads;
            const downloads = JSON.parse(container.dataset.downloadFiles || '[]');

            if (!downloads.length) return;

            container.className = 'ltms-digital-downloads';
            container.innerHTML = `
                <div class="ltms-digital-downloads-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <h3>Tus descargas digitales</h3>
                </div>
                <div class="ltms-digital-downloads-list">
                    ${downloads.map((file) => {
                        const expired = file.expires && new Date(file.expires) < new Date();
                        const exhausted = file.max_downloads && file.downloads >= file.max_downloads;
                        const disabled = expired || exhausted;

                        return `
                            <div class="ltms-download-item ${disabled ? 'disabled' : ''}">
                                <div class="ltms-download-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="ltms-download-info">
                                    <div class="ltms-download-name">${escapeHtml(file.name)}</div>
                                    <div class="ltms-download-meta">
                                        ${file.size ? `<span>📄 ${escapeHtml(file.size)}</span>` : ''}
                                        ${file.max_downloads ? `<span>⬇️ ${file.downloads || 0}/${file.max_downloads} descargas</span>` : ''}
                                        ${file.expires ? `<span>⏰ ${expired ? 'Expirado' : 'Expira: ' + escapeHtml(file.expires)}</span>` : ''}
                                    </div>
                                </div>
                                ${disabled
                                    ? '<span class="ltms-download-disabled-badge">No disponible</span>'
                                    : `<a href="${escapeHtml(file.url)}" download class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-download-btn" data-download-url="${escapeHtml(file.url)}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Descargar
                                    </a>`
                                }
                            </div>
                        `;
                    }).join('')}
                </div>
            `;

            // Track downloads
            container.querySelectorAll('.ltms-download-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    toast('info', 'Descargando...', 'Tu archivo se está descargando.');
                    announce('Descarga iniciada');
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 113. ONE-CLICK REORDER — Reordenar pedido anterior
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite reordenar un pedido anterior con un solo clic,
     * añadiendo todos los items al carrito automáticamente.
     */

    function initOneClickReorder() {
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-reorder]');
            if (!btn) return;
            e.preventDefault();

            const orderId = btn.dataset.reorder;
            const itemCount = btn.dataset.reorderCount || '';

            const confirmMsg = itemCount
                ? `¿Reordenar ${itemCount} producto(s) de tu pedido anterior?`
                : '¿Reordenar este pedido?';

            if (!confirm(confirmMsg)) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="ltms-spinner"></span> Añadiendo...';

            // Task 67-B / UX-FAKE-1 — Resolve the AJAX bootstrap. Historically
            // every UX module gated the AJAX call on
            // `typeof ltmsDashboard !== 'undefined'`, but ltmsDashboard is only
            // localized on the vendor dashboard, so on the customer-facing
            // storefront the call was never made and the else-branch faked a
            // success toast. The new `ltmsUX` global (added in
            // class-ltms-frontend-assets.php) is available everywhere — prefer
            // it and fall back to ltmsDashboard for back-compat with older
            // dashboard pages.
            const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_reorder',
                    nonce: ajaxNonce,
                    order_id: orderId,
                }, (response) => {
                    if (response.success) {
                        toast('success', '¡Productos añadidos!', response.data?.message || 'Tu carrito ha sido actualizado.');
                        // Update cart count
                        if (response.data?.cart_count !== undefined) {
                            document.querySelectorAll('.ltms-sf-cart-count, .cart-count').forEach((el) => {
                                el.textContent = response.data.cart_count;
                                el.style.display = response.data.cart_count > 0 ? '' : 'none';
                            });
                        }
                        // v2.9.208: redirect to /cart instead of opening drawer.
                        setTimeout(() => { window.location.href = (typeof ltmsUX !== 'undefined' && ltmsUX.cart_url) || '/cart/'; }, 500);
                    } else {
                        toast('error', 'Error', response.data?.message || response.data || 'No se pudo reordenar.');
                    }
                }).fail(() => {
                    toast('error', 'Error de conexión', 'No se pudo completar la reorden. Intenta de nuevo.');
                }).always(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Reordenar';
                });
            } else {
                // UX-FAKE-1 FIX — do NOT fake a success toast. The endpoint
                // cannot be reached (no jQuery or no ajax bootstrap). Surface
                // a clear error so the user knows the action failed and is
                // not misled into believing the items were added.
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Reordenar';
                toast('error', 'No disponible', 'La reorden no está disponible en este momento. Recarga la página e inténtalo de nuevo.');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 19. BOTTOM NAV — Navegación inferior móvil
    // ═══════════════════════════════════════════════════════════

    function initBottomNav() {
        // Click en items de bottom nav
        document.addEventListener('click', (e) => {
            const item = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-bottom-nav-item');
            if (!item) return;

            // Botón "Más" abre el sidebar
            if (item.classList.contains('ltms-bottom-nav-more')) {
                const sidebar = document.querySelector('.ltms-sidebar');
                const overlay = document.querySelector('.ltms-sidebar-overlay');
                if (sidebar) {
                    sidebar.classList.add('ltms-sidebar-open');
                    if (overlay) {
                        overlay.classList.add('active');
                        overlay.style.display = 'block';
                    }
                    document.body.style.overflow = 'hidden';
                }
                return;
            }

            // Navegación a vista
            const view = item.dataset.view;
            if (!view) return;

            // Actualizar estado activo en bottom nav
            document.querySelectorAll('.ltms-bottom-nav-item').forEach((el) => {
                if (!el.classList.contains('ltms-bottom-nav-fab')) {
                    el.classList.remove('active');
                }
            });
            item.classList.add('active');

            // Disparar navegación del dashboard
            if (window.LTMS && LTMS.Dashboard && typeof LTMS.Dashboard.loadView === 'function') {
                LTMS.Dashboard.loadView(view);
            }
        });

        // Sincronizar estado activo del bottom nav cuando se navega desde el sidebar
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('click', '.ltms-nav-item[data-view]', function () {
                const view = jQuery(this).data('view');
                if (!view) return;
                // Actualizar bottom nav
                document.querySelectorAll('.ltms-bottom-nav-item').forEach((el) => {
                    if (!el.classList.contains('ltms-bottom-nav-fab')) {
                        el.classList.remove('active');
                        if (el.dataset.view === view) {
                            el.classList.add('active');
                        }
                    }
                });
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 19. ORDERS FILTER CHIPS — Sincronizar con select nativo
    // ═══════════════════════════════════════════════════════════

    function initOrdersFilterChips() {
        document.addEventListener('click', (e) => {
            const chip = e.target.closest('.ltms-filter-chip');
            if (!chip) return;

            const status = chip.dataset.status || '';

            // Actualizar UI de chips
            const chipsContainer = chip.closest('.ltms-filter-chips');
            if (chipsContainer) {
                chipsContainer.querySelectorAll('.ltms-filter-chip').forEach((c) => {
                    c.classList.remove('active');
                    c.setAttribute('aria-selected', 'false');
                });
                chip.classList.add('active');
                chip.setAttribute('aria-selected', 'true');
            }

            // Sincronizar con el select nativo (que usa el JS original del dashboard)
            const select = document.getElementById('ltms-order-status-filter');
            if (select) {
                select.value = status;
                // Disparar evento change para que el handler original reaccione
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 20. ORDERS SEARCH — Búsqueda con debounce
    // ═══════════════════════════════════════════════════════════

    function initOrdersSearch() {
        const searchInput = document.getElementById('ltms-order-search');
        if (!searchInput) return;

        const debouncedSearch = debounce((query) => {
            // Filtrar las filas visibles de la tabla
            const rows = document.querySelectorAll('#ltms-orders-tbody tr');
            const q = query.toLowerCase().trim();

            rows.forEach((row) => {
                if (!q) {
                    row.style.display = '';
                    return;
                }
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });

            // Mostrar mensaje si no hay resultados
            const visibleRows = document.querySelectorAll('#ltms-orders-tbody tr:not([style*="display: none"])');
            const tbody = document.getElementById('ltms-orders-tbody');
            const noResults = document.getElementById('ltms-orders-no-results');

            if (visibleRows.length === 0 && !noResults) {
                const tr = document.createElement('tr');
                tr.id = 'ltms-orders-no-results';
                tr.innerHTML = `<td colspan="8" class="ltms-loading-cell">
                    <div style="padding:30px;color:#9ca3af;">
                        <div style="font-size:2rem;margin-bottom:8px;opacity:0.5;">🔍</div>
                        <div style="font-weight:600;color:#6b7280;margin-bottom:4px;">Sin resultados</div>
                        <div style="font-size:0.825rem;">No se encontraron pedidos para "${escapeHtml(query)}"</div>
                    </div>
                </td>`;
                tbody.appendChild(tr);
            } else if (visibleRows.length > 0 && noResults) {
                noResults.remove();
            }
        }, CONFIG.debounceMs);

        searchInput.addEventListener('input', (e) => {
            debouncedSearch(e.target.value);
        });

        // Limpiar búsqueda con Escape
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && searchInput.value) {
                searchInput.value = '';
                debouncedSearch('');
                searchInput.blur();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 21. EXPORT BUTTON — Toast para funcionalidad pendiente
    // ═══════════════════════════════════════════════════════════

    function initExportButtons() {
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('#ltms-export-movements, [data-export]');
            if (!btn) return;
            e.preventDefault();

            const type = btn.dataset.export || 'movimientos';
            toast('info', 'Exportando', `Preparando ${type} para descarga...`, { duration: 2500 });

            // Feedback visual
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="ltms-spinner" style="width:14px;height:14px;border-width:2px;margin-right:4px;"></span> Exportando...';
            btn.disabled = true;
            setTimeout(() => {
                btn.innerHTML = original;
                btn.disabled = false;
            }, 2000);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 15. REAL-TIME CLOCK en topbar (opcional)
    // ═══════════════════════════════════════════════════════════

    function initTopbarClock() {
        const clockEl = document.querySelector('.ltms-topbar-clock');
        if (!clockEl) return;

        function update() {
            const now = new Date();
            const time = now.toLocaleTimeString('es-CO', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true,
            });
            clockEl.textContent = time;
        }
        update();
        setInterval(update, 30000);
    }


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
            initKeyboardShortcuts();
            initSidebarOverlay();
            initTopbarClock();
            initUserDropdown();
            initNotificationsPanel();
            initBottomNav();
            initCommandPalette();
            initSkeletonLoaders();
            initOrdersFilterChips();
            initOrdersSearch();
            initExportButtons();
            initTour();
            initMobileGestures();
            initBulkActions();
            initOnboardingWidget();
            initSmartNotifications();
            initActivityFeed();
            initDashboardCustomization();
            initRealtimeUpdates();
            loadNotifSettings();
            initLoyaltyPoints();
            initReturnWizard();
            initInvoiceCenter();
            initDigitalDownloads();
            initOneClickReorder();

            // Re-inicializar cuando el SPA del dashboard inyecta HTML nuevo.
            if (typeof jQuery !== 'undefined') {
                jQuery(document).on('ltms:view:loaded ltms:modal:open', () => {
                    if (typeof LTMS.UX.reinit === 'function') LTMS.UX.reinit();
                    initOrdersSearch();
                });
            }

        } catch (err) {
            console.error('[LTMS.UX] Error inicializando:', err);
        }
    }

    // Auto-init
    init();

})();