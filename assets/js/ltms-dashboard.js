/**
 * LT Marketplace Suite - Vendor Dashboard SPA
 * Panel del Vendedor - Single Page Application
 * Version: 1.5.2
 */

/* global ltmsDashboard, jQuery, Chart */

(function ($) {
    'use strict';

    // ── Namespace del Dashboard ──────────────────────────────────
    window.LTMS = window.LTMS || {};

    /**
     * LTMS.Dashboard - SPA del Panel de Vendedor
     */
    LTMS.Dashboard = {

        /** Vista activa actual */
        currentView: 'home',

        /** Cache de datos por vista */
        dataCache: {},

        /** Instancias de Chart.js activas */
        charts: {},

        /** Timer de polling para notificaciones */
        notifTimer: null,

        /** Última fecha de notificación recibida */
        lastNotifDate: null,

        /**
         * Inicializa el SPA completo.
         */
        init() {
            window.ltmsDashboardInstance = this;
            this.bindNavigation();
            this.loadView('home');
            this.startNotificationPolling();
            this.initMobileMenu();
            this.bindLogout();
            this.bindOrderFilter();
            this.initDarkMode();       // v2.9.82 P2
            this.initCsvExport();       // v2.9.82 P2
            this.initBreadcrumbs();     // v2.9.82 P2
        },

        /**
         * Vincula los eventos de navegación del sidebar.
         */
        bindNavigation() {
            const self = this;

            $(document).on('click', '.ltms-nav-item[data-view]', function (e) {
                e.preventDefault();
                const view = $(this).data('view');
                self.loadView(view);

                // Actualizar estado activo en el nav
                $('.ltms-nav-item').removeClass('active');
                $(this).addClass('active');

                // v2.9.78 P1: Sincronizar bottom nav active state.
                $('.ltms-bottom-nav-item').removeClass('active');
                $('.ltms-bottom-nav-item[data-view="' + view + '"]').addClass('active');

                // Actualizar el título del topbar
                const title = $(this).find('.ltms-nav-label').text();
                $('.ltms-topbar-title').text(title);

                // Cerrar sidebar en móvil
                if ($(window).width() <= 768) {
                    $('.ltms-sidebar').removeClass('ltms-sidebar-open');
                }
            });

            // v2.9.78 P1: Bottom navigation handler (mobile).
            $(document).on('click', '.ltms-bottom-nav-item[data-view]', function (e) {
                e.preventDefault();
                const view = $(this).data('view');
                self.loadView(view);
                $('.ltms-bottom-nav-item').removeClass('active');
                $(this).addClass('active');
                $('.ltms-nav-item').removeClass('active');
                $('.ltms-nav-item[data-view="' + view + '"]').addClass('active');
                const title = $(this).find('.ltms-bottom-nav-label').text();
                $('.ltms-topbar-title').text(title);
            });

            // v2.9.78 P1: Bell keyboard support (a11y).
            $(document).on('keydown', '#ltms-notif-bell', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).click();
                }
            });
        },

        /**
         * Carga y renderiza una vista del SPA.
         *
         * @param {string} view Nombre de la vista.
         * @param {boolean} forceRefresh Forzar recarga ignorando caché.
         */
        loadView(view, forceRefresh = false) {
            this.currentView = view;

            // Ocultar todas las secciones y mostrar el loader
            $('.ltms-view-section').hide();
            this.showViewLoader();

            // v2.9.75 FIX: Normalizar el nombre del view para construir el método.
            // Antes, views con guiones como 'shipping-statement' generaban
            // 'loadShipping-statementView' que nunca existe como función.
            // Ahora convertimos 'shipping-statement' → 'ShippingStatement'
            // para generar 'loadShippingStatementView'.
            const normalized = view.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('');
            const loadMethod = 'load' + normalized + 'View';

            if (typeof this[loadMethod] === 'function') {
                this[loadMethod](forceRefresh);
            } else {
                // v2.9.75: Si no hay método específico, mostrar la sección directamente.
                // Esto garantiza que TODAS las vistas carguen, incluso si el AJAX falla.
                this.loadGenericView(view);
            }
        },

        /**
         * Carga la vista Home con métricas y gráficas.
         *
         * @param {boolean} forceRefresh
         */
        loadHomeView(forceRefresh = false) {
            const self = this;
            const cacheKey = 'home';

            if (!forceRefresh && this.dataCache[cacheKey]) {
                this.renderHomeView(this.dataCache[cacheKey]);
                return;
            }

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_dashboard_data',
                    nonce: ltmsDashboard.nonce,
                },
                success: (response) => {
                    if (response.success) {
                        self.dataCache[cacheKey] = response.data;
                        self.renderHomeView(response.data);
                    } else {
                        self.showError('#ltms-view-home', response.data || ltmsDashboard.i18n.error);
                    }
                },
                error: () => self.showError('#ltms-view-home', ltmsDashboard.i18n.error),
            });
        },

        /**
         * Renderiza la vista Home con los datos obtenidos.
         *
         * @param {Object} data Datos del dashboard.
         */
        renderHomeView(data) {
            // Actualizar métricas
            this.updateMetric('.ltms-metric-sales', data.monthly_sales, true);
            this.updateMetric('.ltms-metric-orders', data.monthly_orders, false);
            this.updateMetric('.ltms-metric-commissions', data.monthly_commissions, true);
            this.updateMetric('.ltms-metric-balance', data.wallet_balance, true);

            // M-AUDIT-REG-07: banner de onboarding (puramente informativo, no bloquea nada).
            this.renderOnboardingBanner(data.onboarding);

            // Cargar gráfica de ventas
            this.loadSalesChart();

            // Mostrar la sección
            this.showSection('#ltms-view-home');
        },

        /**
         * Renderiza (o esconde) el banner de checklist de onboarding del vendedor.
         * No bloquea ninguna acción — solo informa qué pasos faltan.
         *
         * @param {Object} ob Datos de onboarding (email_verified, kyc_status, kyc_url, has_products, all_done).
         */
        renderOnboardingBanner(ob) {
            const $banner = $('#ltms-onboarding-banner');
            if (!$banner.length) return;

            // v2.9.71 P3-1: all_done ahora incluye store_configured check.
            // v2.9.71 P3-2: Strings localizadas via ltmsDashboard.i18n.
            const i18n = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.i18n) ? ltmsDashboard.i18n : {};
            const t = (key, fallback) => i18n[key] || fallback;

            if (!ob || ob.all_done) {
                $banner.hide().empty();
                return;
            }

            const kycLabels = {
                none: { text: t('kyc_none', 'Pendiente de iniciar'), color: '#9ca3af' },
                pending: { text: t('kyc_pending', 'En revisión'), color: '#f59e0b' },
                approved: { text: t('kyc_approved', 'Aprobado'), color: '#10b981' },
                rejected: { text: t('kyc_rejected', 'Rechazado — corrige y reenvía'), color: '#ef4444' },
                expired: { text: t('kyc_expired', 'Expirado — renueva'), color: '#6b7280' },
            };
            const kyc = kycLabels[ob.kyc_status] || kycLabels.none;
            const kycDone = ob.kyc_status === 'approved';

            const steps = [
                {
                    done: !!ob.email_verified,
                    icon: '✉️',
                    title: t('ob_email_title', 'Verifica tu email'),
                    detail: ob.email_verified ? t('ob_email_done', 'Verificado') : t('ob_email_pending', 'Revisa tu bandeja de entrada (y spam) para confirmar tu cuenta.'),
                    action: null,
                },
                {
                    done: kycDone,
                    icon: '🪪',
                    title: t('ob_kyc_title', 'Completa tu verificación de identidad (KYC)'),
                    detail: kyc.text,
                    action: kycDone ? null : { label: t('ob_kyc_action', 'Completar KYC'), url: ob.kyc_url },
                },
                {
                    done: !!ob.store_configured,
                    icon: '🏪',
                    title: t('ob_store_title', 'Configura tu tienda'),
                    detail: ob.store_configured ? t('ob_store_done', 'Tienda configurada') : t('ob_store_pending', 'Añade logo, descripción y banner a tu tienda.'),
                    action: ob.store_configured ? null : { label: t('ob_store_action', 'Configurar tienda'), view: 'settings' },
                },
                {
                    done: !!ob.has_products,
                    icon: '🛍️',
                    title: t('ob_product_title', 'Publica tu primer producto'),
                    detail: ob.has_products ? t('ob_product_done', 'Ya tienes productos publicados') : t('ob_product_pending', 'Tu tienda aún no tiene productos visibles.'),
                    action: ob.has_products ? null : { label: t('ob_product_action', 'Agregar producto'), view: 'products' },
                },
            ];

            const stepsHtml = steps.map((s, i) => `
                <div style="display:flex;align-items:center;gap:12px;padding:10px 0;${i < steps.length - 1 ? 'border-bottom:1px solid #e5e7eb;' : ''}">
                    <span style="font-size:1.3rem;flex-shrink:0;">${s.done ? '✅' : s.icon}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:.9rem;color:#111827;${s.done ? 'text-decoration:line-through;color:#9ca3af;' : ''}">${s.title}</div>
                        <div style="font-size:.8rem;color:#6b7280;">${s.detail}</div>
                    </div>
                    ${s.action ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-onboarding-action" data-view="${s.action.view || ''}" data-url="${s.action.url || ''}">${s.action.label}</button>` : ''}
                </div>
            `).join('');

            $banner.html(`
                <div class="ltms-card" style="padding:20px;border-left:4px solid #2563eb;">
                    <div style="font-weight:700;font-size:1rem;color:#111827;margin-bottom:4px;">
                        👋 ${t('ob_welcome', '¡Bienvenido a Lo Tengo!')}
                    </div>
                    <div style="font-size:.85rem;color:#6b7280;margin-bottom:14px;">
                        ${t('ob_subtitle', 'Completa estos pasos para aprovechar al máximo tu tienda.')}
                    </div>
                    ${stepsHtml}
                </div>
            `).show();

            $banner.find('.ltms-onboarding-action').off('click').on('click', function () {
                const view = $(this).data('view');
                const url = $(this).data('url');
                if (view) {
                    LTMS.Dashboard.loadView(view);
                } else if (url) {
                    window.location.href = url;
                }
            });
        },

        /**
         * Carga la gráfica de ventas del vendedor.
         */
        loadSalesChart() {
            const canvas = document.getElementById('ltms-vendor-sales-chart');
            if (!canvas || typeof Chart === 'undefined') return;

            // Destruir instancia anterior si existe
            if (this.charts.sales) {
                this.charts.sales.destroy();
            }

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_analytics_data',
                    nonce: ltmsDashboard.nonce,
                    period: 'month',
                },
                success: (response) => {
                    if (!response.success) return;
                    const d = response.data;

                    this.charts.sales = new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels: d.labels,
                            datasets: [
                                {
                                    label: 'Ventas',
                                    data: d.sales,
                                    borderColor: '#1a5276',
                                    backgroundColor: 'rgba(26,82,118,0.08)',
                                    tension: 0.4,
                                    fill: true,
                                },
                                {
                                    label: 'Comisiones',
                                    data: d.commissions,
                                    borderColor: '#27ae60',
                                    backgroundColor: 'rgba(39,174,96,0.08)',
                                    tension: 0.4,
                                    fill: true,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: true, position: 'top' },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => {
                                            return ctx.dataset.label + ': ' + this.formatMoney(ctx.parsed.y);
                                        },
                                    },
                                },
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: (val) => this.formatMoney(val, true),
                                    },
                                },
                            },
                        },
                    });
                },
            });
        },

        /**
         * Estado de la vista de Pedidos: página/filtro actuales, para que
         * paginación y filtro trabajen sobre el mismo estado en vez de
         * mandar siempre page:1 (bug de la versión anterior).
         */
        ordersState: { page: 1, perPage: 20, status: '', totalPages: 1 },

        /**
         * Carga la vista de Pedidos (primera carga / al navegar al tab).
         */
        loadOrdersView() {
            this.ordersState = { page: 1, perPage: 20, status: '', totalPages: 1 };
            this.fetchOrders();
        },

        /**
         * Hace la petición AJAX real usando el estado actual (página/filtro)
         * y renderiza tabla + controles de paginación.
         */
        fetchOrders() {
            const self = this;
            const $tbody = $('#ltms-orders-tbody');
            $tbody.html('<tr><td colspan="7" class="ltms-empty-cell">' + (ltmsDashboard.i18n.loading || 'Cargando...') + '</td></tr>');

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_orders_data',
                    nonce: ltmsDashboard.nonce,
                    page: self.ordersState.page,
                    per_page: self.ordersState.perPage,
                    status: self.ordersState.status,
                },
                success(response) {
                    if (response.success) {
                        self.ordersState.totalPages = response.data.total_pages || 1;
                        self.renderOrdersTable(response.data.orders);
                        self.renderOrdersPagination(response.data);
                        self.showSection('#ltms-view-orders');
                    } else {
                        $tbody.html('<tr><td colspan="7" class="ltms-empty-cell">' + (response.data || ltmsDashboard.i18n.error) + '</td></tr>');
                    }
                },
                error: () => $tbody.html('<tr><td colspan="7" class="ltms-empty-cell">' + ltmsDashboard.i18n.error + '</td></tr>'),
            });
        },

        /**
         * Renderiza la tabla de pedidos del vendedor.
         * P-01: columna de tipo de envío, badge pickup diferenciado, etiquetas en español.
         * audit-pedidos: filas clicables que abren el modal de detalle.
         *
         * @param {Array} orders Lista de pedidos.
         */
        renderOrdersTable(orders) {
            const $tbody = $('#ltms-orders-tbody');
            $tbody.empty();

            if (!orders || orders.length === 0) {
                $tbody.append('<tr><td colspan="7" class="ltms-empty-cell">No tienes pedidos aún.</td></tr>');
                return;
            }

            orders.forEach(order => {
                const statusClass = this.getOrderStatusClass(order.status);
                const statusLabel = this.getOrderStatusLabel(order.status);

                // Columna de tipo de envío: ícono de tienda para pickup, texto normal para el resto
                let shippingCell = '';
                if (order.is_pickup) {
                    const addr = order.store_info && order.store_info.address
                        ? `<div style="font-size:.75rem;color:#6b7280;margin-top:2px;">${this.escapeHtml(order.store_info.address)}</div>`
                        : '';
                    shippingCell = `<span style="color:#1a5276;font-weight:600;">🏪 Recogida</span>${addr}`;
                } else {
                    shippingCell = `<span style="color:#6b7280;font-size:.85rem;">${this.escapeHtml(order.shipping_label || '—')}</span>`;
                }

                $tbody.append(`
                    <tr class="ltms-order-row" data-order-id="${order.id}" style="cursor:pointer;" tabindex="0" role="button" aria-label="Ver detalle del pedido #${order.number}">
                        <td>#${order.number}</td>
                        <td>${this.escapeHtml(order.customer)}</td>
                        <td>${order.items_count} item(s)</td>
                        <td><strong>${order.formatted}</strong></td>
                        <td>${shippingCell}</td>
                        <td><span class="ltms-badge ${statusClass}">${statusLabel}</span></td>
                        <td>${order.is_redi ? '<span style="background:#E80001;color:#fff;padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:600;">ReDi ' + (order.redi_role === 'origin' ? '📍' : '🔁') + '</span>' : '<span style="color:#ccc;">—</span>'}</td>
                        <td>${order.date}</td>
                    </tr>
                `);
            });
        },

        /**
         * Renderiza los controles de paginación (Anterior / página X de Y / Siguiente)
         * debajo de la tabla de pedidos.
         *
         * @param {Object} data Respuesta de ltms_get_orders_data (total, page, total_pages).
         */
        renderOrdersPagination(data) {
            let $pager = $('#ltms-orders-pagination');
            if (!$pager.length) {
                $pager = $('<div id="ltms-orders-pagination" style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;"></div>');
                $('#ltms-orders-tbody').closest('.ltms-card').after($pager);
            }

            const page = data.page || 1;
            const totalPages = data.total_pages || 1;
            const total = data.total || 0;

            if (total === 0) { $pager.empty(); return; }

            $pager.html(`
                <span>Página ${page} de ${totalPages} · ${total} pedido(s)</span>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-orders-prev" ${page <= 1 ? 'disabled' : ''} aria-label="Página anterior">‹ Anterior</button>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-orders-next" ${page >= totalPages ? 'disabled' : ''} aria-label="Página siguiente">Siguiente ›</button>
                </div>
            `);
        },

        /**
         * Carga la vista de Billetera.
         */
        loadWalletView() {
            const self = this;

            // v2.9.75: Mostrar la sección inmediatamente, antes del AJAX.
            // Así, si el AJAX falla, el vendor aún ve la estructura de la vista
            // (aunque sin datos dinámicos) en vez de quedarse en "Cargando...".
            self.showSection('#ltms-view-wallet');

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_wallet_data',
                    nonce: ltmsDashboard.nonce,
                },
                success(response) {
                    if (response.success) {
                        self.renderWalletView(response.data);
                    } else {
                        self.showError('#ltms-view-wallet', response.data);
                    }
                },
                error: () => {
                    // No mostrar error — la vista ya está visible con datos estáticos.
                },
            });
        },

        /**
         * Renderiza la vista de billetera con balance y movimientos.
         *
         * @param {Object} data Datos de la billetera.
         */
        renderWalletView(data) {
            // Actualizar el balance mostrado
            $('.ltms-wallet-balance').text(this.formatMoney(data.balance));
            $('.ltms-wallet-available').text(this.formatMoney(data.available));
            $('.ltms-wallet-held').text(this.formatMoney(data.held));

            // Renderizar tabla de transacciones
            const $tbody = $('#ltms-wallet-tbody');
            $tbody.empty();

            if (!data.transactions || data.transactions.length === 0) {
                $tbody.append('<tr><td colspan="4" class="ltms-empty-cell">No hay movimientos.</td></tr>');
                return;
            }

            data.transactions.forEach(tx => {
                const isCredit = parseFloat(tx.amount) >= 0;
                // C5-4 fix: handler devuelve tx.formatted (no tx.formatted_amount)
                const displayAmount = tx.formatted || tx.formatted_amount || tx.amount || '—';
                // C5-5 fix: handler devuelve tx.date (no tx.created_at)
                const displayDate = tx.date || tx.created_at || '—';
                $tbody.append(`
                    <tr>
                        <td>${displayDate}</td>
                        <td>${this.escapeHtml(tx.description || '')}</td>
                        <td><span class="ltms-badge ${this.getTxTypeBadge(tx.type)}">${tx.type}</span></td>
                        <td class="${isCredit ? 'credit' : 'debit'}">
                            ${isCredit ? '+' : ''}${displayAmount}
                        </td>
                    </tr>
                `);
            });
        },

        /**
         * Abre el modal de solicitud de retiro.
         */
        openPayoutModal() {
            // M-201 FIX v2: cargar billetera para tener datos frescos,
            // luego abrir el modal con loadWalletView callback o directo.
            const self = this;
            const openModal = () => {
                const balance = parseFloat(ltmsDashboard.wallet_balance) || 0;
                const $modal = $('#ltms-modal-payout');
                if ($modal.length === 0) return;
                $('#ltms-payout-amount').attr('max', balance);
                $('#ltms-payout-balance-display').text(self.formatMoney(balance));
                // Usar LTMS.Modal si está disponible, sino mostrar directamente
                if (typeof LTMS.Modal !== 'undefined' && typeof LTMS.Modal.open === 'function') {
                    LTMS.Modal.open('ltms-modal-payout');
                } else {
                    $modal.addClass('ltms-modal-open');
                    $('body').addClass('ltms-modal-body-lock');
                }
            };
            // Si el modal no está en el DOM, navegar a wallet primero
            if ($('#ltms-modal-payout').length === 0) {
                this.loadView('wallet');
                const wait = setInterval(() => {
                    if ($('#ltms-modal-payout').length > 0) {
                        clearInterval(wait);
                        openModal();
                    }
                }, 100);
                setTimeout(() => clearInterval(wait), 5000);
            } else {
                openModal();
            }
        },

        /**
         * Envía la solicitud de retiro.
         */
        submitPayoutRequest() {
            if (!confirm(ltmsDashboard.i18n.confirm_payout)) return;

            const amount     = parseFloat($('#ltms-payout-amount').val());
            const accountId  = $('#ltms-payout-account').val();
            const method     = $('#ltms-payout-method').val() || 'bank_transfer';

            if (!amount || amount <= 0 || !accountId) {
                LTMS.Modal.showError('ltms-modal-payout', 'Completa todos los campos.');
                return;
            }

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_request_payout',
                    nonce: ltmsDashboard.nonce,
                    amount,
                    bank_account_id: accountId,
                    method,
                },
                success: (response) => {
                    LTMS.Modal.close('ltms-modal-payout');
                    if (response.success) {
                        this.showToast('success', response.data.message);
                        this.loadView('wallet', true);
                    } else {
                        // M-123 FIX: response.data puede ser objeto o string — extraer mensaje seguro
                        const errMsg = (typeof response.data === 'string')
                            ? response.data
                            : (response.data?.message || ltmsDashboard.i18n.error);
                        this.showToast('error', errMsg);
                    }
                },
            });
        },

        // ── Notificaciones ────────────────────────────────────────

        /**
         * Inicia el polling de notificaciones.
         */
        startNotificationPolling() {
            this.fetchNotifications();
            this.notifTimer = setInterval(() => {
                this.fetchNotifications();
            }, ltmsDashboard.polling_interval || 30000);
        },

        /**
         * Obtiene notificaciones no leídas del servidor.
         */
        fetchNotifications() {
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_notifications',
                    nonce: ltmsDashboard.nonce,
                    since: this.lastNotifDate,
                },
                success: (response) => {
                    if (!response.success) return;

                    // M-15 FIX: usar `count` (total real no leídas) para el badge SIEMPRE,
                    // independientemente de si hay nuevas. Esto permite que el badge se
                    // ponga en 0 cuando el vendedor marca todo como leído.
                    this.updateNotificationBadge(response.data.count);

                    // Solo renderizar si hay notificaciones nuevas desde `since`
                    const newNotifs = response.data.notifications || [];
                    if (newNotifs.length > 0) {
                        this.renderNotifications(newNotifs);
                        this.lastNotifDate = newNotifs[0].created_at;
                    }
                },
            });
        },

        /**
         * Actualiza el contador de notificaciones en el topbar.
         *
         * @param {number} count Número de notificaciones.
         */
        updateNotificationBadge(count) {
            const $badge = $('.ltms-badge-count');
            if (count > 0) {
                $badge.text(count > 99 ? '99+' : count).show();
            } else {
                $badge.hide();
            }
        },

        /**
         * Renderiza las notificaciones en el panel lateral.
         *
         * @param {Array} notifications Lista de notificaciones.
         */
        renderNotifications(notifications, replaceAll = false) {
            const $list = $('#ltms-notif-list');

            // M-22 FIX: $list.empty() en el polling borraba todas las notificaciones
            // existentes en cada ciclo. Solo vaciar en carga inicial (replaceAll=true).
            // En polling, prepend sin borrar + anti-duplicados por data-id.
            if (replaceAll) {
                $list.empty();
            }

            notifications.forEach(notif => {
                if ($list.find('[data-id="' + notif.id + '"]').length > 0) return;

                const $item = $('<div>').addClass('ltms-notif-item unread').attr('data-id', notif.id);
                $item.html(`
                    <p class="ltms-notif-title">${this.escapeHtml(notif.title)}</p>
                    <p class="ltms-notif-msg">${this.escapeHtml(notif.message)}</p>
                    <span class="ltms-notif-time">${notif.created_at}</span>
                `);
                $item.on('click', () => this.markNotificationRead(notif.id, $item));
                $list.prepend($item);
            });
        },

        /**
         * Marca una notificación como leída.
         *
         * @param {number} id    ID de la notificación.
         * @param {jQuery} $item Elemento jQuery del item.
         */
        markNotificationRead(id, $item) {
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_mark_notification_read',
                    nonce: ltmsDashboard.nonce,
                    notification_id: id,
                },
                success: () => {
                    $item.removeClass('unread');
                    const $badge = $('.ltms-badge-count');
                    const current = parseInt($badge.text()) || 0;
                    if (current <= 1) {
                        $badge.hide();
                    } else {
                        $badge.text(current - 1);
                    }
                },
            });
        },

        // ── UI Helpers ────────────────────────────────────────────

        /**
         * Muestra una sección del SPA.
         *
         * @param {string} selector Selector CSS de la sección.
         */
        showSection(selector) {
            $('.ltms-view-loader').hide();
            $(selector).show();
        },

        /**
         * Muestra un loader mientras carga la vista.
         */
        showViewLoader() {
            if ($('.ltms-view-loader').length === 0) {
                $('<div class="ltms-view-loader" style="text-align:center;padding:40px;color:#888;">Cargando...</div>')
                    .appendTo('#ltms-main-content');
            }
            $('.ltms-view-loader').show();
        },

        /**
         * Muestra un mensaje de error en una sección.
         *
         * @param {string} selector Selector CSS.
         * @param {string} message  Mensaje de error.
         */
        showError(selector, message) {
            $('.ltms-view-loader').hide();
            // M-123 FIX: message puede ser objeto cuando viene de wp_send_json_error
            const msg = (typeof message === 'string') ? message
                : (message?.message || message?.data || ltmsDashboard?.i18n?.error || 'Error');
            $(selector).html(`<div class="ltms-notice ltms-notice-error"><p>${this.escapeHtml(msg)}</p></div>`).show();
        },

        /**
         * Muestra una notificación tipo toast.
         *
         * @param {string} type    'success'|'error'|'info'.
         * @param {string} message Mensaje.
         */
        showToast(type, message) {
            const $toast = $('<div>').addClass('ltms-toast ltms-toast-' + type).text(message);
            $('body').append($toast);
            $toast.fadeIn(200);
            setTimeout(() => $toast.fadeOut(300, function() { $(this).remove(); }), 4000);
        },

        /**
         * Actualiza el valor de una métrica con animación.
         *
         * @param {string}  selector  Selector CSS del elemento.
         * @param {number}  value     Nuevo valor.
         * @param {boolean} isMoney   Si formatear como dinero.
         */
        updateMetric(selector, value, isMoney = false) {
            const $el = $(selector);
            if ($el.length === 0) return;
            const display = isMoney ? this.formatMoney(value) : parseInt(value).toLocaleString('es-CO');
            $el.text(display);
        },

        /**
         * Inicializa el menú móvil (toggle del sidebar).
         */
        initMobileMenu() {
            if (window.innerWidth > 768) return;

            const sidebar = document.querySelector('.ltms-sidebar');
            const overlay = document.querySelector('.ltms-sidebar-overlay');
            const topbar  = document.querySelector('.ltms-topbar');
            const main    = document.querySelector('.ltms-main-content');
            const TOPBAR_H = 52;

            // Medir el header del tema (elementos fixed/sticky fuera del panel)
            const getThemeHeaderH = () => {
                let maxBottom = 0;
                document.querySelectorAll('*').forEach(el => {
                    if (el.closest('#ltms-dashboard-container') || el.closest('.ltms-dashboard-container')) return;
                    const s = window.getComputedStyle(el);
                    if (s.position !== 'fixed' && s.position !== 'sticky') return;
                    if (s.display === 'none') return;
                    const r = el.getBoundingClientRect();
                    if (r.height < 10 || r.height > 350 || r.top < -5 || r.top > 80 || r.width < 100) return;
                    if (r.bottom > maxBottom) maxBottom = r.bottom;
                });
                return Math.round(maxBottom);
            };

            const themeH = getThemeHeaderH();

            // Mover elementos al <body>
            // Elementor aplica transform a sus secciones, lo que hace que
            // position:fixed sea relativo al padre en vez del viewport.
            [sidebar, overlay, topbar].forEach(el => {
                if (el && el.parentElement !== document.body) document.body.appendChild(el);
            });

            // Posicionar topbar justo debajo del header del tema
            if (topbar) {
                Object.assign(topbar.style, {
                    position: 'fixed',
                    top: themeH + 'px',
                    left: '0',
                    right: '0',
                    width: '100%',
                    height: TOPBAR_H + 'px',
                    zIndex: '2147483645',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    padding: '0 12px',
                    background: '#1a5276',
                    color: '#fff',
                    boxShadow: '0 2px 8px rgba(0,0,0,0.3)',
                    boxSizing: 'border-box'
                });
                const title = topbar.querySelector('.ltms-topbar-title');
                if (title) Object.assign(title.style, { flex: '1', textAlign: 'center', color: '#fff', fontSize: '0.95rem', fontWeight: '600' });
                const menuBtn = topbar.querySelector('.ltms-mobile-menu-btn');
                if (menuBtn) Object.assign(menuBtn.style, { background: 'rgba(255,255,255,0.2)', color: '#fff', border: 'none' });
                topbar.querySelectorAll('.ltms-btn').forEach(b => { b.style.borderColor = 'rgba(255,255,255,0.5)'; b.style.color = '#fff'; });
                const notif = topbar.querySelector('.ltms-topbar-notif');
                if (notif) notif.style.color = '#fff';
                const storeEl = topbar.querySelector('[style*="color:#374151"]');
                if (storeEl) storeEl.style.color = '#fff';
            }

            // Sidebar y overlay arrancan debajo del topbar del panel
            const panelTop = themeH + TOPBAR_H;
            if (sidebar) { sidebar.style.top = panelTop + 'px'; sidebar.style.height = 'calc(100vh - ' + panelTop + 'px)'; }
            if (overlay) { overlay.style.top = panelTop + 'px'; overlay.style.height = 'calc(100vh - ' + panelTop + 'px)'; }
            if (main) main.style.paddingTop = panelTop + 'px';

            const open  = () => { sidebar?.classList.add('ltms-sidebar-open'); overlay?.classList.add('active'); document.body.style.overflow = 'hidden'; };
            const close = () => { sidebar?.classList.remove('ltms-sidebar-open'); overlay?.classList.remove('active'); document.body.style.overflow = ''; };

            $(document).on('click', '.ltms-mobile-menu-btn', e => { e.stopPropagation(); sidebar?.classList.contains('ltms-sidebar-open') ? close() : open(); });
            $(document).on('click', '.ltms-sidebar-overlay', close);
            $(document).on('click', '.ltms-sidebar-close-btn', close);
            $(document).on('click', '.ltms-nav-item', () => close());
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        },

        /**
         * Vincula el botón de logout.
         */
        bindLogout() {
            $(document).on('click', '.ltms-logout-btn', (e) => {
                e.preventDefault();
                window.location.href = ltmsDashboard.logout_url;
            });
        },

        /**
         * Vincula el select de filtro de estado de pedidos.
         */
        bindOrderFilter() {
            const self = this;
            $(document).on('change', '#ltms-order-status-filter', function () {
                self.ordersState.status = $(this).val() === 'all' ? '' : $(this).val();
                self.ordersState.page = 1;
                self.fetchOrders();
            });

            // Paginación
            $(document).on('click', '#ltms-orders-prev', function () {
                if (self.ordersState.page > 1) {
                    self.ordersState.page -= 1;
                    self.fetchOrders();
                }
            });
            $(document).on('click', '#ltms-orders-next', function () {
                if (self.ordersState.page < self.ordersState.totalPages) {
                    self.ordersState.page += 1;
                    self.fetchOrders();
                }
            });

            // Fila clicable → abre modal de detalle (clic o Enter/Espacio por accesibilidad)
            $(document).on('click', '.ltms-order-row', function () {
                self.openOrderDetail($(this).data('order-id'));
            });
            $(document).on('keydown', '.ltms-order-row', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.openOrderDetail($(this).data('order-id'));
                }
            });

            // Cambiar estado desde el modal de detalle
            $(document).on('click', '.ltms-order-status-action', function () {
                const orderId = $('#ltms-modal-order-detail').data('order-id');
                const newStatus = $(this).data('status');
                self.updateOrderStatus(orderId, newStatus);
            });
        },

        /**
         * Abre el modal de detalle de un pedido y carga su información completa.
         *
         * @param {number} orderId
         */
        openOrderDetail(orderId) {
            const self = this;
            const $modal = $('#ltms-modal-order-detail');
            $modal.data('order-id', orderId);
            $('#ltms-order-detail-body').html('<div style="text-align:center;padding:30px;color:#9ca3af;">Cargando...</div>');

            if (typeof LTMS.Modal !== 'undefined' && typeof LTMS.Modal.open === 'function') {
                LTMS.Modal.open('ltms-modal-order-detail');
            }

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: { action: 'ltms_get_order_detail', nonce: ltmsDashboard.nonce, order_id: orderId },
                success(response) {
                    if (response.success) {
                        self.renderOrderDetail(response.data);
                    } else {
                        $('#ltms-order-detail-body').html('<div style="text-align:center;padding:30px;color:#dc2626;">' + (response.data || 'Error al cargar el pedido.') + '</div>');
                    }
                },
                error: () => $('#ltms-order-detail-body').html('<div style="text-align:center;padding:30px;color:#dc2626;">' + ltmsDashboard.i18n.error + '</div>'),
            });
        },

        /**
         * Renderiza el contenido del modal de detalle de pedido.
         *
         * @param {Object} d Datos devueltos por ltms_get_order_detail.
         */
        renderOrderDetail(d) {
            const itemsHtml = d.items.map(it => `
                <tr>
                    <td>${this.escapeHtml(it.name)}${it.sku ? `<div style="font-size:.75rem;color:#9ca3af;">SKU: ${this.escapeHtml(it.sku)}</div>` : ''}</td>
                    <td style="text-align:center;">${it.qty}</td>
                    <td style="text-align:right;">${it.total}</td>
                </tr>
            `).join('');

            const storeHtml = d.is_pickup && d.store_info && d.store_info.address
                ? `<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 12px;margin-top:10px;font-size:.85rem;">
                       🏪 <strong>Recogida en tienda</strong><br>${this.escapeHtml(d.store_info.address)}
                   </div>`
                : '';

            const transitionsHtml = (d.allowed_transitions || []).map(status => {
                const label = this.getOrderStatusLabel(status);
                return `<button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-order-status-action" data-status="${status}">${label}</button>`;
            }).join(' ');

            const notesHtml = (d.notes || []).length
                ? d.notes.map(n => `<div style="font-size:.8rem;color:#6b7280;border-left:2px solid #e5e7eb;padding-left:8px;margin-bottom:6px;">${this.escapeHtml(n.content)} <span style="color:#9ca3af;">· ${n.date}</span></div>`).join('')
                : '<div style="font-size:.8rem;color:#9ca3af;">Sin notas.</div>';

            $('#ltms-order-detail-body').html(`
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
                    <div>
                        <h3 style="margin:0;">Pedido #${d.number}</h3>
                        <span style="font-size:.85rem;color:#6b7280;">${d.date}</span>
                    </div>
                    <span class="ltms-badge ${this.getOrderStatusClass(d.status)}">${d.status_label}</span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;font-size:.85rem;">
                    <div>
                        <strong>Cliente</strong><br>
                        ${this.escapeHtml(d.customer)}<br>
                        ${d.customer_email ? this.escapeHtml(d.customer_email) + '<br>' : ''}
                        ${d.customer_phone ? this.escapeHtml(d.customer_phone) : ''}
                    </div>
                    <div>
                        <strong>Envío</strong><br>
                        ${this.escapeHtml(d.shipping_label)}
                        ${storeHtml}
                    </div>
                </div>

                <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                    <thead><tr style="font-size:.78rem;color:#9ca3af;border-bottom:1px solid #e5e7eb;">
                        <th style="text-align:left;padding:6px 0;">Producto</th>
                        <th style="text-align:center;padding:6px 0;">Cant.</th>
                        <th style="text-align:right;padding:6px 0;">Total</th>
                    </tr></thead>
                    <tbody>${itemsHtml}</tbody>
                    <tfoot>
                        <tr><td colspan="2" style="text-align:right;padding-top:8px;color:#6b7280;">Subtotal</td><td style="text-align:right;padding-top:8px;">${d.subtotal}</td></tr>
                        <tr><td colspan="2" style="text-align:right;color:#6b7280;">Envío</td><td style="text-align:right;">${d.shipping_total}</td></tr>
                        <tr><td colspan="2" style="text-align:right;font-weight:700;">Total</td><td style="text-align:right;font-weight:700;">${d.total}</td></tr>
                    </tfoot>
                </table>

                ${d.customer_note ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;font-size:.85rem;"><strong>Nota del cliente:</strong> ${this.escapeHtml(d.customer_note)}</div>` : ''}

                <div style="margin-bottom:14px;">
                    <strong style="font-size:.85rem;">Notas del pedido</strong>
                    <div style="margin-top:6px;">${notesHtml}</div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        ${transitionsHtml || '<span style="font-size:.8rem;color:#9ca3af;">Sin acciones disponibles para este estado.</span>'}
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        ${d.is_redi ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" onclick="LTMS.Dashboard.openIncidentModal(${d.id})" style="border-color:#E80001;color:#E80001;">⚠️ Abrir Novedad</button>` : ''}
                        <a href="${d.edit_url}" target="_blank" rel="noopener" class="ltms-btn ltms-btn-outline ltms-btn-sm" aria-label="Ver pedido completo en WordPress">Ver en WordPress ↗</a>
                    </div>
                </div>
            `);
        },

        /**
         * AUDIT-REDI-UX-GAPS GAP-11: Abre el modal de crear incidente
         * desde el detalle de un pedido ReDi.
         */
        openIncidentModal(orderId) {
            // Navegar a la vista de Novedades + abrir el modal de creación.
            this.loadView('incidents');
            // Esperar a que la vista cargue, luego abrir el modal con el order_id prellenado.
            setTimeout(function() {
                if (typeof LTMSIncidents !== 'undefined' && LTMSIncidents.openNewModal) {
                    LTMSIncidents.openNewModal(orderId);
                } else {
                    // Fallback: mostrar un prompt simple.
                    var $ = jQuery;
                    if ($('#ltms-incident-new-modal').length) {
                        $('#ltms-incident-order-id').val(orderId);
                        $('#ltms-incident-new-modal').show();
                    }
                }
            }, 500);
        },

        /**
         * Cambia el estado de un pedido (acción del vendedor desde el modal de detalle).
         *
         * @param {number} orderId
         * @param {string} newStatus
         */
        updateOrderStatus(orderId, newStatus) {
            const self = this;
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: { action: 'ltms_update_order_status', nonce: ltmsDashboard.nonce, order_id: orderId, status: newStatus },
                success(response) {
                    if (response.success) {
                        self.openOrderDetail(orderId); // recarga el modal con el nuevo estado
                        self.fetchOrders(); // refresca la tabla detrás
                    } else {
                        alert(response.data || 'No se pudo cambiar el estado.');
                    }
                },
                error: () => alert(ltmsDashboard.i18n.error),
            });
        },
        loadProductsView(forceRefresh = false) {
            const self = this;
            self.showViewLoader();
            $.ajax({
                url: ltmsDashboard.ajax_url, method: 'POST',
                data: { action: 'ltms_get_products_data', nonce: ltmsDashboard.nonce },
                success(response) {
                    $('.ltms-view-loader').hide();
                    self.renderProductsView(response.success ? response.data : {});
                    self.showSection('#ltms-view-products');
                },
                error: () => { $('.ltms-view-loader').hide(); self.showSection('#ltms-view-products'); },
            });
        },
        renderProductsView(data) {
            const products = (data && data.products) ? data.products : [];
            let rows = products.length === 0
                ? '<tr><td colspan="6" class="ltms-empty-cell">Aún no tienes productos. Crea tu primer producto con el botón de arriba.</td></tr>'
                : products.map(p => `<tr><td style="width:60px;padding:4px;"><img src="${p.image||''}" style="width:50px;height:50px;object-fit:cover;border-radius:6px;background:#f0f0f0;" onerror="this.style.background='#e0e0e0';this.src='';" /></td><td>${this.escapeHtml(p.name)}</td><td>${this.formatMoney(p.price)}</td><td>${this.escapeHtml(p.status)}</td><td>${({'physical':'📦 Físico','product':'📦 Físico','digital':'💾 Digital','service':'🔧 Servicio','booking':'🏨 Turismo'}[p.product_type] || '📦 Físico')}</td><td>${p.stock ?? '-'}</td><td><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
  <button class="ltms-btn ltms-btn-sm ltms-edit-product-btn" data-id="${p.id}" style="background:#1976d2;color:#fff;border:none;font-weight:600;">✏️ Editar</button>
  <button class="ltms-btn ltms-btn-sm ltms-toggle-product-btn" data-id="${p.id}" data-status="${p.status}" style="${p.status==='publish'?'background:#f59e0b;color:#fff;border:none;font-weight:600;':'background:#16a34a;color:#fff;border:none;font-weight:600;'}">${p.status==='publish'?'⏸ Pausar':'▶ Publicar'}</button>
  <button class="ltms-btn ltms-btn-sm ltms-delete-product-btn" data-id="${p.id}" data-name="${this.escapeHtml(p.name)}" style="background:transparent;color:#dc2626;border:1px solid #dc2626;font-weight:600;">🗑</button>
</div></td></tr>`).join('');
            const addUrl = (ltmsDashboard.add_product_url || '/wp-admin/post-new.php?post_type=product');
            $('#ltms-view-products').html(`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;"><h3>Mis Productos</h3><button class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn">+ Nuevo Producto</button></div><div class="ltms-table-wrap"><table class="ltms-table"><thead><tr><th style="width:60px;"></th><th>Producto</th><th>Precio</th><th>Estado</th><th>Tipo</th><th>Stock</th><th>Acción</th></tr></thead><tbody>${rows}</tbody></table></div>`);
        $(document).off('click','#ltms-add-product-btn').on('click','#ltms-add-product-btn', function(e){ e.stopPropagation(); e.preventDefault(); LTMS.Dashboard.loadNewProductView(); });
            $(document).off('click','.ltms-edit-product-btn').on('click','.ltms-edit-product-btn', function(e){ e.stopPropagation(); e.preventDefault(); var pid=$(this).data('id'); LTMS.Dashboard.loadEditProductView(pid); });
            $(document).off('click','.ltms-toggle-product-btn').on('click','.ltms-toggle-product-btn', function(e){
                e.stopPropagation(); e.preventDefault();
                var pid=$(this).data('id');
                var status=$(this).data('status');
                var newStatus = (status==='publish') ? 'draft' : 'publish';
                var label = (status==='publish') ? 'Despublicar' : 'Publicar';
                var nonce=(typeof ltmsDashboard!=='undefined')?ltmsDashboard.nonce:'';
                var ajaxUrl=(typeof ltmsDashboard!=='undefined')?ltmsDashboard.ajax_url:'/wp-admin/admin-ajax.php';
                var $btn=$(this);
                $btn.prop('disabled',true).text('...');
                $.ajax({ url:ajaxUrl, type:'POST', data:{ action:'ltms_toggle_product_status', nonce:nonce, product_id:pid, new_status:newStatus },
                    success:function(r){
                        if(r.success){ LTMS.Dashboard.loadView('products'); }
                        else{ alert('Error: '+(r.data||'No se pudo cambiar estado')); $btn.prop('disabled',false).text(label); }
                    },
                    error:function(){ alert('Error de red'); $btn.prop('disabled',false).text(label); }
                });
            });
            $(document).off('click','.ltms-delete-product-btn').on('click','.ltms-delete-product-btn', function(e){
                e.stopPropagation(); e.preventDefault();
                var pid=$(this).data('id');
                var pname=$(this).data('name') || 'este producto';
                if(!confirm('\u00bfEliminar "' + pname + '"? Esta accion no se puede deshacer.')) return;
                var nonce=(typeof ltmsDashboard!=='undefined')?ltmsDashboard.nonce:'';
                var ajaxUrl=(typeof ltmsDashboard!=='undefined')?ltmsDashboard.ajax_url:'/wp-admin/admin-ajax.php';
                var $btn=$(this);
                $btn.prop('disabled',true).text('Eliminando...');
                $.ajax({ url:ajaxUrl, type:'POST', data:{ action:'ltms_delete_product', nonce:nonce, product_id:pid },
                    success:function(r){
                        if(r.success){ LTMS.Dashboard.loadView('products'); }
                        else{ alert('Error: '+(r.data||'No se pudo eliminar')); $btn.prop('disabled',false).text('Eliminar'); }
                    },
                    error:function(){ alert('Error de red'); $btn.prop('disabled',false).text('Eliminar'); }
                });
            });
        },
        loadNewProductView() {
            const self = this;
            const nonce = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '';
            // Cargar categorías
            jQuery.ajax({
                url: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: { action: 'ltms_get_categories', nonce: nonce },
                success: function(res) {
                    let catOptions = '<option value="">-- Sin categoría --</option>';
                    if (res.success && res.data.categories) {
                        res.data.categories.forEach(function(c) {
                            catOptions += '<option value="' + c.id + '">' + c.name + '</option>';
                        });
                    }
                    var rediMinRaw = (ltmsDashboard.redi_min_rate !== undefined ? parseFloat(ltmsDashboard.redi_min_rate) : 5);
                    var rediMaxRaw = (ltmsDashboard.redi_max_rate !== undefined ? parseFloat(ltmsDashboard.redi_max_rate) : 40);
                    var rediMin = rediMinRaw < 1 ? Math.round(rediMinRaw * 100) : rediMinRaw;
                    var rediMax = rediMaxRaw < 1 ? Math.round(rediMaxRaw * 100) : rediMaxRaw;
                    const html = '<div class="ltms-new-product-form" style="max-width:600px;margin:0 auto;">' +
                        '<h3 style="margin-bottom:20px;">Nuevo Producto</h3>' +
                        '<div id="ltms-np-msg" style="display:none;padding:10px;border-radius:6px;margin-bottom:15px;"></div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Nombre del producto *</label>' +
                            '<input type="text" id="ltms-np-name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="Nombre del producto">' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Precio (COP) *</label>' +
                            '<input type="number" id="ltms-np-price" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="0" min="0">' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Descripción</label>' +
                            '<textarea id="ltms-np-desc" rows="4" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="Descripción del producto"></textarea>' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Categoría</label>' +
                            '<select id="ltms-np-cat" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' + catOptions + '</select>' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Tipo *</label>' +
                            '<select id="ltms-np-type" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' +
                                '<option value="physical">📦 Físico</option>' +
                                '<option value="digital">💾 Digital</option>' +
                                '<option value="service">🔧 Servicio</option>' +
                                '<option value="booking">🏨 Turismo</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Stock (dejar vacío = ilimitado)</label>' +
                            '<input type="number" id="ltms-np-stock" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="Cantidad en stock" min="0">' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Precio de oferta (COP) <small style="font-weight:400;color:#666;">Opcional — debe ser menor al precio regular</small></label>' +
                            '<input type="number" id="ltms-np-sale-price" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="Dejar vacío si no hay oferta" min="0">' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Visibilidad en catálogo</label>' +
                            '<select id="ltms-np-visibility" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' +
                            '<option value="visible">Visible en catálogo y búsqueda</option>' +
                            '<option value="catalog">Solo en catálogo</option>' +
                            '<option value="search">Solo en búsqueda</option>' +
                            '<option value="hidden">Oculto (no visible)</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:8px;">Peso y dimensiones <small style="font-weight:400;color:#666;">(para envíos — dejar vacío si no aplica)</small></label>' +
                            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
                            '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Peso (kg)</label><input type="number" id="ltms-np-weight" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" placeholder="0.000" min="0" step="0.001"></div>' +
                            '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Largo (cm)</label><input type="number" id="ltms-np-length" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" placeholder="0" min="0"></div>' +
                            '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Ancho (cm)</label><input type="number" id="ltms-np-width" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" placeholder="0" min="0"></div>' +
                            '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Alto (cm)</label><input type="number" id="ltms-np-height" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" placeholder="0" min="0"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Imagen del producto</label>' +
                            '<div id="ltms-np-img-preview" style="width:120px;height:120px;border:2px dashed #ddd;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;margin-bottom:8px;overflow:hidden;"><span style="color:#999;font-size:13px;">+ Imagen</span></div>' +
                            '<input type="file" id="ltms-np-img-input" accept="image/*" style="display:none;">' +
                            '<input type="hidden" id="ltms-np-img-id" value="">' +
                        '</div>' +
                        '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Imágenes adicionales (galería)</label>' +
                            '<div id="ltms-np-gallery-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;"></div>' +
                            '<button type="button" id="ltms-np-add-gallery-btn" style="padding:8px 16px;border:1px dashed #aaa;border-radius:6px;background:#f9f9f9;cursor:pointer;">+ Agregar imagen</button>' +
                            '<input type="file" id="ltms-np-gallery-input" accept="image/*" multiple style="position:fixed;top:-9999px;left:-9999px;opacity:0;width:1px;height:1px;" >' +
                            '<input type="hidden" id="ltms-np-gallery-ids" value="">' +
                        '</div>' +
                        '<div style="background:#f0f7ff;border:1px solid #c2d9f5;border-radius:8px;padding:16px;margin-top:16px;">' +
                            '<label style="font-weight:600;font-size:14px;color:#1a4a8a;display:block;margin-bottom:10px;">🔁 Distribución ReDi</label>' +
                            '<label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">' +
                                '<input type="checkbox" id="ltms-np-redi-enabled" style="width:16px;height:16px;">' +
                                '<span style="font-size:13px;color:#333;">Habilitar este producto para reventa por otros vendedores</span>' +
                            '</label>' +
                            '<div id="ltms-np-redi-rate-wrap" style="display:none;">' +
                                '<label style="font-size:13px;font-weight:500;color:#555;">Tasa de comisión ReDi — entre ' + rediMin + '% y ' + rediMax + '%</label>' +
                                '<input type="number" id="ltms-np-redi-rate" min="' + rediMin + '" max="' + rediMax + '" step="1" placeholder="Ej: ' + Math.round((rediMin + rediMax) / 2) + '" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;" oninput="(function(el){var v=parseFloat(el.value),mn=' + rediMin + ',mx=' + rediMax + ';el.style.borderColor=(isNaN(v)||v<mn||v>mx)?\'#c00\':\'#4CAF50\';})(this)">' +
                                '<p style="font-size:11px;color:#777;margin-top:4px;">Porcentaje del precio que recibirá el revendedor al vender este producto.</p>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;">' +
                            '<button id="ltms-np-submit" class="ltms-btn ltms-btn-primary" style="flex:1;min-width:100px;">Publicar Producto</button>' +
                            '<button id="ltms-np-draft" class="ltms-btn" style="flex:1;min-width:100px;background:#f5f5f5;color:#333;">Guardar Borrador</button>' +
                            '<button id="ltms-np-cancel" class="ltms-btn" style="flex:1;min-width:80px;background:#f5f5f5;color:#333;">Cancelar</button>' +
                        '</div>' +
                    '</div>';
                    jQuery('#ltms-view-products').html(html);
                    jQuery('#ltms-np-img-preview').on('click', function(){ jQuery('#ltms-np-img-input').trigger('click'); });
                    var npGalleryIds = [];
                    jQuery('#ltms-np-add-gallery-btn').on('click', function(){ var gi=document.getElementById('ltms-np-gallery-input'); gi.multiple=true; gi.click(); });
                    jQuery('#ltms-np-gallery-input').on('change', function() {
                        var files = this.files;
                        if (!files.length) return;
                        Array.from(files).forEach(function(file) {
                            var fd = new FormData();
                            fd.append('action','ltms_upload_product_image');
                            fd.append('nonce', nonce);
                            fd.append('image', file);
                            var ajaxUrl2 = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php';
                            jQuery.ajax({ url: ajaxUrl2, type:'POST', data:fd, processData:false, contentType:false,
                                success: function(r) {
                                    if (r.success) {
                                        npGalleryIds.push(r.data.attachment_id);
                                        jQuery('#ltms-np-gallery-ids').val(npGalleryIds.join(','));
                                        var thumb = '<div style="position:relative;width:80px;height:80px;">' +
                                            '<img src="'+r.data.url+'" style="width:80px;height:80px;object-fit:cover;border-radius:4px;">' +
                                            '<button type="button" data-id="'+r.data.attachment_id+'" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:1;" class="ltms-np-rm-gallery">×</button>' +
                                        '</div>';
                                        jQuery('#ltms-np-gallery-wrap').append(thumb);
                                    }
                                }
                            });
                        });
                        this.value = '';
                    });
                    jQuery('#ltms-np-gallery-wrap').on('click', '.ltms-np-rm-gallery', function() {
                        var rid = parseInt(jQuery(this).data('id'));
                        npGalleryIds = npGalleryIds.filter(function(i){ return i !== rid; });
                        jQuery('#ltms-np-gallery-ids').val(npGalleryIds.join(','));
                        jQuery(this).parent().remove();
                    });

                    // Upload imagen
                    jQuery('#ltms-np-img-input').on('change', function() {
                        const file = this.files[0];
                        if (!file) return;
                        const formData = new FormData();
                        formData.append('action', 'ltms_upload_product_image');
                        formData.append('nonce', nonce);
                        formData.append('image', file);
                        const ajaxUrl = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php';
                        jQuery.ajax({ url: ajaxUrl, type: 'POST', data: formData, processData: false, contentType: false,
                            success: function(r) {
                                if (r.success) {
                                    jQuery('#ltms-np-img-id').val(r.data.attachment_id);
                                    jQuery('#ltms-np-img-preview').html('<img src="' + r.data.url + '" style="width:100%;height:100%;object-fit:cover;">');
                                }
                            }
                        });
                    });

                    // Cancelar
                    jQuery('#ltms-np-cancel').on('click', function() { self.loadView('products'); });

                    // Submit
                    function submitProduct(status) {
                        const name = jQuery('#ltms-np-name').val().trim();
                        const price = jQuery('#ltms-np-price').val();
                        if (!name || !price) {
                            jQuery('#ltms-np-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'}).text('Nombre y precio son requeridos.');
                            return;
                        }
                        // Validar tasa ReDi si está activa
                        if (jQuery('#ltms-np-redi-enabled').is(':checked')) {
                            var npRediVal = parseFloat(jQuery('#ltms-np-redi-rate').val());
                            var npRediMinRaw = (ltmsDashboard.redi_min_rate !== undefined ? parseFloat(ltmsDashboard.redi_min_rate) : 5);
                            var npRediMaxRaw = (ltmsDashboard.redi_max_rate !== undefined ? parseFloat(ltmsDashboard.redi_max_rate) : 40);
                            var npRediMin = npRediMinRaw < 1 ? Math.round(npRediMinRaw * 100) : npRediMinRaw;
                            var npRediMax = npRediMaxRaw < 1 ? Math.round(npRediMaxRaw * 100) : npRediMaxRaw;
                            if (isNaN(npRediVal) || npRediVal < npRediMin || npRediVal > npRediMax) {
                                jQuery('#ltms-np-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'})
                                    .text('⚠️ La tasa ReDi debe estar entre ' + npRediMin + '% y ' + npRediMax + '%.');
                                jQuery('#ltms-np-redi-rate').focus().css('border-color','#c00');
                                return;
                            }
                            jQuery('#ltms-np-redi-rate').css('border-color','#ccc');
                        }
                        jQuery('#ltms-np-submit, #ltms-np-draft').prop('disabled', true).text('Guardando...');
                        const ajaxUrl = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php';
                        jQuery.ajax({ url: ajaxUrl, type: 'POST', data: {
                            action: 'ltms_create_product', nonce: nonce,
                            name: name, price: price, status: status,
                            description: jQuery('#ltms-np-desc').val(),
                            category_id: jQuery('#ltms-np-cat').val(),
                            product_type: jQuery('#ltms-np-type').val(),
                            stock: jQuery('#ltms-np-stock').val(),
                            sale_price: jQuery('#ltms-np-sale-price').val(),
                            catalog_visibility: jQuery('#ltms-np-visibility').val(),
                            weight: jQuery('#ltms-np-weight').val(),
                            dim_length: jQuery('#ltms-np-length').val(),
                            dim_width: jQuery('#ltms-np-width').val(),
                            dim_height: jQuery('#ltms-np-height').val(),
                            image_id: jQuery('#ltms-np-img-id').val(),
                            gallery_ids: jQuery('#ltms-np-gallery-ids').val(),
                            redi_enabled: jQuery('#ltms-np-redi-enabled').is(':checked') ? 1 : 0,
                            redi_rate: jQuery('#ltms-np-redi-rate').val()
                        }, success: function(r) {
                            if (r.success) {
                                jQuery('#ltms-np-msg').show().css({background:'#efe','color':'#060','border':'1px solid #060'}).text('✅ ' + r.data.message);
                                setTimeout(function() { self.loadView('products'); }, 1500);
                            } else {
                                jQuery('#ltms-np-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'}).text('Error: ' + r.data);
                                jQuery('#ltms-np-submit, #ltms-np-draft').prop('disabled', false);
                                jQuery('#ltms-np-submit').text('Publicar Producto');
                                jQuery('#ltms-np-draft').text('Guardar Borrador');
                            }
                        }});
                    }
                    jQuery('#ltms-np-redi-enabled').on('change', function() {
                        jQuery('#ltms-np-redi-rate-wrap').toggle(this.checked);
                        if (!this.checked) jQuery('#ltms-np-redi-rate').val('');
                    });
                    jQuery('#ltms-np-submit').on('click', function() { submitProduct('pending'); });
                    jQuery('#ltms-np-draft').on('click', function() { submitProduct('draft'); });
                }
            });
        },

        loadEditProductView(productId) {
            const nonce = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '';
            const ajaxUrl = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php';
            jQuery('#ltms-view-products').html('<div style="padding:40px;text-align:center;color:#999;">Cargando producto...</div>');
            // Cargar producto y categorías en paralelo
            var prodReq = jQuery.ajax({ url: ajaxUrl, type: 'POST', data: { action: 'ltms_get_product', nonce: nonce, product_id: productId } });
            var catReq  = jQuery.ajax({ url: ajaxUrl, type: 'POST', data: { action: 'ltms_get_categories', nonce: nonce } });
            jQuery.when(prodReq, catReq).done(function(prodRes, catRes) {
                var p = prodRes[0].data;
                var cats = catRes[0].data.categories || [];
                if (!prodRes[0].success) {
                    jQuery('#ltms-view-products').html('<div style="padding:20px;color:red;">Error: ' + (prodRes[0].data || 'Producto no encontrado') + '</div>');
                    return;
                }
                var catOptions = '<option value="">-- Sin categoría --</option>';
                cats.forEach(function(c) {
                    catOptions += '<option value="' + c.id + '"' + (c.id == p.category_id ? ' selected' : '') + '>' + c.name + '</option>';
                });
                var imgHtml = p.image_url
                    ? '<img src="' + p.image_url + '" style="width:100%;height:100%;object-fit:cover;">'
                    : '<span style="color:#999;font-size:13px;">+ Imagen</span>';
                var rediMinRaw = (ltmsDashboard.redi_min_rate !== undefined ? parseFloat(ltmsDashboard.redi_min_rate) : 5);
                var rediMaxRaw = (ltmsDashboard.redi_max_rate !== undefined ? parseFloat(ltmsDashboard.redi_max_rate) : 40);
                var rediMin = rediMinRaw < 1 ? Math.round(rediMinRaw * 100) : rediMinRaw;
                var rediMax = rediMaxRaw < 1 ? Math.round(rediMaxRaw * 100) : rediMaxRaw;
                var html = '<div class="ltms-new-product-form" style="max-width:600px;margin:0 auto;">' +
                    '<h3 style="margin-bottom:20px;">Editar Producto</h3>' +
                    '<div id="ltms-ep-msg" style="display:none;padding:10px;border-radius:6px;margin-bottom:15px;"></div>' +
                    '<input type="hidden" id="ltms-ep-product-id" value="' + p.id + '">' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Nombre del producto *</label>' +
                        '<input type="text" id="ltms-ep-name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" value="' + (p.name || '') + '">' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Precio (COP) *</label>' +
                        '<input type="number" id="ltms-ep-price" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" value="' + (p.price || '') + '" min="0">' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Descripción</label>' +
                        '<textarea id="ltms-ep-desc" rows="4" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' + (p.description || '') + '</textarea>' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Categoría</label>' +
                        '<select id="ltms-ep-cat" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' + catOptions + '</select>' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Tipo *</label>' +
                        '<select id="ltms-ep-type" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' +
                            '<option value="physical"'  + (['physical','product',''].indexOf(p.product_type||'') >= 0 ? ' selected' : '') + '>📦 Físico</option>' +
                            '<option value="digital"'  + (p.product_type === 'digital'  ? ' selected' : '') + '>💾 Digital</option>' +
                            '<option value="service"'  + (p.product_type === 'service'  ? ' selected' : '') + '>🔧 Servicio</option>' +
                            '<option value="booking"'  + (p.product_type === 'booking'  ? ' selected' : '') + '>🏨 Turismo</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Stock (vacío = ilimitado)</label>' +
                        '<input type="number" id="ltms-ep-stock" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" value="' + (p.stock !== null ? p.stock : '') + '" min="0">' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Precio de oferta (COP) <small style="font-weight:400;color:#666;">Opcional — debe ser menor al precio regular</small></label>' +
                        '<input type="number" id="ltms-ep-sale-price" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" value="' + (p.sale_price || '') + '" placeholder="Dejar vacío si no hay oferta" min="0">' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Visibilidad en catálogo</label>' +
                        '<select id="ltms-ep-visibility" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">' +
                        '<option value="visible"' + (p.catalog_visibility === 'visible' ? ' selected' : '') + '>Visible en catálogo y búsqueda</option>' +
                        '<option value="catalog"' + (p.catalog_visibility === 'catalog' ? ' selected' : '') + '>Solo en catálogo</option>' +
                        '<option value="search"' + (p.catalog_visibility === 'search' ? ' selected' : '') + '>Solo en búsqueda</option>' +
                        '<option value="hidden"' + (p.catalog_visibility === 'hidden' ? ' selected' : '') + '>Oculto (no visible)</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:8px;">Peso y dimensiones <small style="font-weight:400;color:#666;">(para envíos — dejar vacío si no aplica)</small></label>' +
                        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
                        '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Peso (kg)</label><input type="number" id="ltms-ep-weight" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" value="' + (p.weight || '') + '" placeholder="0.000" min="0" step="0.001"></div>' +
                        '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Largo (cm)</label><input type="number" id="ltms-ep-length" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" value="' + (p.length || '') + '" placeholder="0" min="0"></div>' +
                        '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Ancho (cm)</label><input type="number" id="ltms-ep-width" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" value="' + (p.width || '') + '" placeholder="0" min="0"></div>' +
                        '<div><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Alto (cm)</label><input type="number" id="ltms-ep-height" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" value="' + (p.height || '') + '" placeholder="0" min="0"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Imagen del producto</label>' +
                        '<div id="ltms-ep-img-preview" style="width:120px;height:120px;border:2px dashed #ddd;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;margin-bottom:8px;overflow:hidden;">' + imgHtml + '</div>' +
                        '<input type="file" id="ltms-ep-img-input" accept="image/*" style="display:none;">' +
                        '<input type="hidden" id="ltms-ep-img-id" value="' + (p.image_id || '') + '">' +
                    '</div>' +
                    '<div class="ltms-form-group" style="margin-bottom:15px;">' +
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Imágenes adicionales (galería)</label>' +
                        '<div id="ltms-ep-gallery-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;"></div>' +
                        '<button type="button" id="ltms-ep-add-gallery-btn" style="padding:8px 16px;border:1px dashed #aaa;border-radius:6px;background:#f9f9f9;cursor:pointer;">+ Agregar imagen</button>' +
                        '<input type="file" id="ltms-ep-gallery-input" accept="image/*" multiple style="position:fixed;top:-9999px;left:-9999px;opacity:0;width:1px;height:1px;" >' +
                        '<input type="hidden" id="ltms-ep-gallery-ids" value="' + (p.gallery_ids ? p.gallery_ids.join(",") : "") + '">' +
                    '</div>' +
                    '<div style="background:#f0f7ff;border:1px solid #c2d9f5;border-radius:8px;padding:16px;margin-top:16px;">' +
                        '<label style="font-weight:600;font-size:14px;color:#1a4a8a;display:block;margin-bottom:10px;">🔁 Distribución ReDi</label>' +
                        '<label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">' +
                            '<input type="checkbox" id="ltms-ep-redi-enabled" ' + (p.redi_enabled ? 'checked' : '') + ' style="width:16px;height:16px;">' +
                            '<span style="font-size:13px;color:#333;">Habilitar este producto para reventa por otros vendedores</span>' +
                        '</label>' +
                        '<div id="ltms-ep-redi-rate-wrap" style="display:' + (p.redi_enabled ? 'block' : 'none') + ';">' +
                            '<label style="font-size:13px;font-weight:500;color:#555;">Tasa de comisión ReDi — entre ' + rediMin + '% y ' + rediMax + '%</label>' +
                            '<input type="number" id="ltms-ep-redi-rate" min="' + rediMin + '" max="' + rediMax + '" step="1" value="' + (p.redi_rate || '') + '" placeholder="Ej: ' + Math.round((rediMin + rediMax) / 2) + '" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;" oninput="(function(el){var v=parseFloat(el.value),mn=' + rediMin + ',mx=' + rediMax + ';el.style.borderColor=(isNaN(v)||v<mn||v>mx)?\'#c00\':\'#4CAF50\';})(this)">' +
                            '<p style="font-size:11px;color:#777;margin-top:4px;">Porcentaje del precio que recibirá el revendedor al vender este producto.</p>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;">' +
                        '<button id="ltms-ep-submit" class="ltms-btn ltms-btn-primary" style="flex:1;min-width:120px;">Guardar Cambios</button>' +
                        '<button id="ltms-ep-cancel" class="ltms-btn" style="flex:1;min-width:80px;background:#f5f5f5;color:#333;">Cancelar</button>' +
                    '</div>' +
                '</div>';
                jQuery('#ltms-view-products').html(html);
                // Click en preview abre file input
                jQuery('#ltms-ep-img-preview').on('click', function(){ jQuery('#ltms-ep-img-input').trigger('click'); });
                // Cargar galería existente
                var epGalleryIds = p.gallery_ids ? p.gallery_ids.slice() : [];
                if (p.gallery_urls && p.gallery_urls.length) {
                    p.gallery_urls.forEach(function(url, idx) {
                        var gid = p.gallery_ids[idx];
                        var thumb = '<div style="position:relative;width:80px;height:80px;">' +
                            '<img src="'+url+'" style="width:80px;height:80px;object-fit:cover;border-radius:4px;">' +
                            '<button type="button" data-id="'+gid+'" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:1;" class="ltms-ep-rm-gallery">×</button>' +
                        '</div>';
                        jQuery('#ltms-ep-gallery-wrap').append(thumb);
                    });
                }
                jQuery('#ltms-ep-add-gallery-btn').on('click', function(){ var gi=document.getElementById('ltms-ep-gallery-input'); gi.multiple=true; gi.click(); });
                jQuery('#ltms-ep-gallery-input').on('change', function() {
                    var files = this.files;
                    if (!files.length) return;
                    Array.from(files).forEach(function(file) {
                        var fd = new FormData();
                        fd.append('action','ltms_upload_product_image');
                        fd.append('nonce', nonce);
                        fd.append('image', file);
                        jQuery.ajax({ url: ajaxUrl, type:'POST', data:fd, processData:false, contentType:false,
                            success: function(r) {
                                if (r.success) {
                                    epGalleryIds.push(r.data.attachment_id);
                                    jQuery('#ltms-ep-gallery-ids').val(epGalleryIds.join(','));
                                    var thumb = '<div style="position:relative;width:80px;height:80px;">' +
                                        '<img src="'+r.data.url+'" style="width:80px;height:80px;object-fit:cover;border-radius:4px;">' +
                                        '<button type="button" data-id="'+r.data.attachment_id+'" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;line-height:1;" class="ltms-ep-rm-gallery">×</button>' +
                                    '</div>';
                                    jQuery('#ltms-ep-gallery-wrap').append(thumb);
                                }
                            }
                        });
                    });
                    this.value = '';
                });
                jQuery('#ltms-ep-gallery-wrap').on('click', '.ltms-ep-rm-gallery', function() {
                    var rid = parseInt(jQuery(this).data('id'));
                    epGalleryIds = epGalleryIds.filter(function(i){ return i !== rid; });
                    jQuery('#ltms-ep-gallery-ids').val(epGalleryIds.join(','));
                    jQuery(this).parent().remove();
                });
                // Upload imagen
                jQuery('#ltms-ep-img-input').on('change', function() {
                    var file = this.files[0];
                    if (!file) return;
                    var formData = new FormData();
                    formData.append('action', 'ltms_upload_product_image');
                    formData.append('nonce', nonce);
                    formData.append('image', file);
                    jQuery.ajax({ url: ajaxUrl, type: 'POST', data: formData, processData: false, contentType: false,
                        success: function(r) {
                            if (r.success) {
                                jQuery('#ltms-ep-img-id').val(r.data.attachment_id);
                                jQuery('#ltms-ep-img-preview').html('<img src="' + r.data.url + '" style="width:100%;height:100%;object-fit:cover;">');
                            }
                        }
                    });
                });
                // Cancelar
                jQuery('#ltms-ep-cancel').on('click', function() { LTMS.Dashboard.loadView('products'); });
                // ReDi toggle
                jQuery('#ltms-ep-redi-enabled').on('change', function() {
                    jQuery('#ltms-ep-redi-rate-wrap').toggle(this.checked);
                    if (!this.checked) jQuery('#ltms-ep-redi-rate').val('');
                });
                // Guardar
                jQuery('#ltms-ep-submit').on('click', function() {
                    var name = jQuery('#ltms-ep-name').val().trim();
                    var price = jQuery('#ltms-ep-price').val();
                    if (!name || !price) {
                        jQuery('#ltms-ep-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'}).text('Nombre y precio son requeridos.');
                        return;
                    }
                    // Validar tasa ReDi si está activa
                    if (jQuery('#ltms-ep-redi-enabled').is(':checked')) {
                        var epRediVal = parseFloat(jQuery('#ltms-ep-redi-rate').val());
                        var epRediMinRaw = (ltmsDashboard.redi_min_rate !== undefined ? parseFloat(ltmsDashboard.redi_min_rate) : 5);
                        var epRediMaxRaw = (ltmsDashboard.redi_max_rate !== undefined ? parseFloat(ltmsDashboard.redi_max_rate) : 40);
                        var epRediMin = epRediMinRaw < 1 ? Math.round(epRediMinRaw * 100) : epRediMinRaw;
                        var epRediMax = epRediMaxRaw < 1 ? Math.round(epRediMaxRaw * 100) : epRediMaxRaw;
                        if (isNaN(epRediVal) || epRediVal < epRediMin || epRediVal > epRediMax) {
                            jQuery('#ltms-ep-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'})
                                .text('⚠️ La tasa ReDi debe estar entre ' + epRediMin + '% y ' + epRediMax + '%.');
                            jQuery('#ltms-ep-redi-rate').focus().css('border-color','#c00');
                            return;
                        }
                        jQuery('#ltms-ep-redi-rate').css('border-color','#ccc');
                    }
                    jQuery('#ltms-ep-submit').prop('disabled', true).text('Guardando...');
                    jQuery.ajax({ url: ajaxUrl, type: 'POST', data: {
                        action: 'ltms_update_product', nonce: nonce,
                        product_id: jQuery('#ltms-ep-product-id').val(),
                        name: name, price: price,
                        description: jQuery('#ltms-ep-desc').val(),
                        category_id: jQuery('#ltms-ep-cat').val(),
                        product_type: jQuery('#ltms-ep-type').val(),
                        stock: jQuery('#ltms-ep-stock').val(),
                        sale_price: jQuery('#ltms-ep-sale-price').val(),
                        catalog_visibility: jQuery('#ltms-ep-visibility').val(),
                        weight: jQuery('#ltms-ep-weight').val(),
                        dim_length: jQuery('#ltms-ep-length').val(),
                        dim_width: jQuery('#ltms-ep-width').val(),
                        dim_height: jQuery('#ltms-ep-height').val(),
                        image_id: jQuery('#ltms-ep-img-id').val(),
                        gallery_ids: jQuery('#ltms-ep-gallery-ids').val(),
                        redi_enabled: jQuery('#ltms-ep-redi-enabled').is(':checked') ? 1 : 0,
                        redi_rate: jQuery('#ltms-ep-redi-rate').val()
                    }, success: function(r) {
                        if (r.success) {
                            jQuery('#ltms-ep-msg').show().css({background:'#efe','color':'#060','border':'1px solid #060'}).text('✅ ' + r.data.message);
                            setTimeout(function() { LTMS.Dashboard.loadView('products'); }, 1500);
                        } else {
                            jQuery('#ltms-ep-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'}).text('Error: ' + r.data);
                            jQuery('#ltms-ep-submit').prop('disabled', false).text('Guardar Cambios');
                        }
                    }});
                });
            });
        },

        loadSettingsView(forceRefresh = false) {
            const self = this;
            self.showViewLoader();
            $.ajax({
                url: ltmsDashboard.ajax_url, method: 'POST',
                data: { action: 'ltms_get_vendor_settings', nonce: ltmsDashboard.nonce },
                success(response) {
                    $('.ltms-view-loader').hide();
                    self.renderSettingsView(response.success ? response.data : {});
                    self.showSection('#ltms-view-settings');
                },
                error: () => { $('.ltms-view-loader').hide(); self.renderSettingsView({}); self.showSection('#ltms-view-settings'); },
            });
        },
        renderSettingsView(data) {
            const kyc = data.kyc_status || 'pending';
            const kycLabel = {pending:'Pendiente',approved:'Aprobado',rejected:'Rechazado'}[kyc] || kyc;
            const kycColor = {pending:'#f59e0b',approved:'#10b981',rejected:'#ef4444'}[kyc] || '#888';
            const store = data.store || {};
            const kycUrl = ltmsDashboard.kyc_url || '/verificacion-identidad/';
            const kycBlock = kyc !== 'approved'
                ? `<p style="margin:10px 0;color:#666;">Para solicitar retiros, debes completar la verificación de identidad.</p><a href="${kycUrl}" class="ltms-btn ltms-btn-outline">Completar KYC</a>`
                : '<p style="color:#10b981;">✓ Identidad verificada.</p>';
            $('#ltms-view-settings').html(`
                <h3 style="margin-bottom:20px;">Configuración de Mi Cuenta</h3>
                <div class="ltms-card" style="margin-bottom:20px;padding:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong>Verificación de Identidad (KYC)</strong>
                        <span style="color:${kycColor};font-weight:600;">${kycLabel}</span>
                    </div>${kycBlock}
                </div>
                <div class="ltms-card" style="padding:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Datos de la Tienda</h4>
                    <div class="ltms-form-group"><label>Nombre de la Tienda</label><input type="text" class="ltms-form-control" name="store_name" value="${this.escapeHtml(store.name||'')}" placeholder="Mi Tienda"></div>
                    <div class="ltms-form-group"><label>Teléfono de Contacto</label><input type="text" class="ltms-form-control" name="store_phone" value="${this.escapeHtml(store.phone||'')}" placeholder="+57 300 000 0000"></div>
                    <div class="ltms-form-group"><label>Descripción</label><textarea class="ltms-form-control" name="store_description" rows="3">${this.escapeHtml(store.description||'')}</textarea></div>
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:12px;">
                        <p style="font-size:0.78rem;font-weight:600;color:#374151;margin:0 0 12px;text-transform:uppercase;letter-spacing:.5px;">🏦 Cuenta Bancaria para Retiros</p>
                        <p style="font-size:0.75rem;color:#6b7280;margin:0 0 12px;">Esta cuenta se usará automáticamente al solicitar un retiro.</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div class="ltms-form-group" style="margin:0;"><label>Banco</label><input type="text" class="ltms-form-control" name="ltms_bank_name" value="${this.escapeHtml(store.bank_name||'')}" placeholder="Ej: Bancolombia"></div>
                            <div class="ltms-form-group" style="margin:0;"><label>Tipo de Cuenta</label><select class="ltms-form-control" name="ltms_bank_account_type"><option value="ahorros" ${(store.bank_account_type||'ahorros')==='ahorros'?'selected':''}>Ahorros</option><option value="corriente" ${(store.bank_account_type||'')==='corriente'?'selected':''}>Corriente</option><option value="nequi" ${(store.bank_account_type||'')==='nequi'?'selected':''}>Nequi</option><option value="daviplata" ${(store.bank_account_type||'')==='daviplata'?'selected':''}>Daviplata</option></select></div>
                        </div>
                        <div class="ltms-form-group" style="margin-bottom:10px;"><label>Número de Cuenta</label><input type="text" class="ltms-form-control" name="ltms_bank_account_number" value="${this.escapeHtml(store.bank_account_number||'')}" placeholder="Ej: 69812345678"></div>
                        <div class="ltms-form-group" style="margin:0;"><label>Nombre del Titular</label><input type="text" class="ltms-form-control" name="ltms_bank_account_holder" value="${this.escapeHtml(store.bank_account_holder||'')}" placeholder="Nombre como aparece en el banco"></div>
                    </div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-settings-btn">💾 Guardar Cambios</button>
                    <span class="ltms-settings-msg" style="margin-left:10px;display:none;"></span>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Perfil Público de la Tienda</h4>
                    <div class="ltms-form-group"><label>Nombre Público</label><input type="text" class="ltms-form-control" name="ltms_store_name" value="${this.escapeHtml(store.store_name||store.name||'')}" placeholder="Nombre visible al comprador"></div>
                    <div class="ltms-form-group"><label>Dirección</label><input type="text" class="ltms-form-control" name="ltms_store_address" value="${this.escapeHtml(store.store_address||'')}" placeholder="Calle, carrera, barrio"></div>
                    <div class="ltms-form-group"><label>Ciudad</label><input type="text" class="ltms-form-control" name="ltms_store_city" value="${this.escapeHtml(store.store_city||'')}" placeholder="Bogotá, Medellín..."></div>
                    <div class="ltms-form-group"><label>Teléfono Público</label><input type="text" class="ltms-form-control" name="ltms_store_phone" value="${this.escapeHtml(store.store_phone||store.phone||'')}" placeholder="+57 300 000 0000"></div>
                    <div class="ltms-form-group"><label>Horario de Atención</label><textarea class="ltms-form-control" name="ltms_store_schedule" rows="2" placeholder="Lun-Vie 8am-6pm">${this.escapeHtml(store.store_schedule||'')}</textarea></div>
                    <div class="ltms-form-group"><label>Categorías (separadas por coma)</label><input type="text" class="ltms-form-control" name="ltms_store_categories" value="${this.escapeHtml(store.store_categories||'')}" placeholder="Ropa, Calzado, Accesorios"></div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-profile-btn">💾 Guardar Perfil</button>
                    <span class="ltms-profile-msg" style="margin-left:10px;display:none;"></span>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:4px;">🖼️ Banner de la Tienda</h4>
                    <p style="font-size:.8rem;color:#6b7280;margin:0 0 12px;">
                        Esta imagen aparece como fondo del encabezado de tu página pública en
                        <strong>lo-tengo.com.co/vendedor/tu-tienda</strong>.
                    </p>
                    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.8rem;color:#0369a1;line-height:1.6;">
                        <strong>📐 Tamaño recomendado:</strong> 1440 × 320 píxeles (relación 4.5:1)<br>
                        <strong>📱 En móvil</strong> se recorta al centro — pon lo importante en el medio.<br>
                        <strong>⚖️ Peso máximo:</strong> 3 MB · Formatos: JPG, PNG, WebP<br>
                        <strong>💡 Consejo:</strong> fondos sólidos, degradados o fotos con poco texto funcionan mejor.
                    </div>
                    <div id="ltms-banner-current-wrap" style="display:none;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <p style="font-size:.78rem;font-weight:600;color:#374151;margin:0;">🖼️ Banner actual</p>
                            <button type="button" class="ltms-delete-banner-btn"
                                    style="display:inline-flex;align-items:center;gap:5px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:5px 12px;font-size:.78rem;font-weight:600;cursor:pointer;">
                                🗑️ Eliminar
                            </button>
                        </div>
                        <img id="ltms-banner-current" src="" alt="Banner actual"
                             style="width:100%;max-height:130px;object-fit:cover;border-radius:8px;border:1.5px solid #e5e7eb;display:block;">
                    </div>
                    <div id="ltms-banner-preview-wrap" style="display:none;margin-bottom:12px;">
                        <p style="font-size:.75rem;color:#9ca3af;margin:0 0 4px;">Vista previa (proporcional):</p>
                        <img id="ltms-banner-preview" src="" alt="Preview banner"
                             style="width:100%;max-height:100px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <label style="display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1.5px solid #d1d5db;border-radius:6px;padding:7px 14px;cursor:pointer;font-size:.85rem;font-weight:500;">
                            📂 Seleccionar imagen
                            <input type="file" id="ltms-banner-file" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        </label>
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-upload-banner-btn">🖼️ Subir Banner</button>
                        <span class="ltms-banner-msg" style="font-size:.85rem;display:none;"></span>
                    </div>
                    <p id="ltms-banner-filename" style="font-size:.78rem;color:#6b7280;margin:6px 0 0;display:none;"></p>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Zona de Despacho</h4>
                    <div class="ltms-form-group"><label>Ciudades de cobertura (separadas por coma)</label><input type="text" class="ltms-form-control" id="ltms-dz-cities" value="${this.escapeHtml((store.delivery_zone&&store.delivery_zone.cities||[]).join(', '))}" placeholder="Bogotá, Soacha"></div>
                    <div class="ltms-form-group"><label>Radio máximo (km)</label><input type="number" class="ltms-form-control" id="ltms-dz-radius" min="0" value="${store.delivery_zone&&store.delivery_zone.radius_km||0}"></div>
                    <div class="ltms-form-group"><label>Envío gratis desde (COP)</label><input type="number" class="ltms-form-control" id="ltms-dz-free" min="0" value="${store.delivery_zone&&store.delivery_zone.free_from||0}"></div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-zone-btn">💾 Guardar Zona</button>
                    <span class="ltms-zone-msg" style="margin-left:10px;display:none;"></span>
                </div>`);
            // Analytics card (appended separately to avoid nested backtick issues)
            if (data.store.vendor_ga4_enabled || data.store.vendor_pixel_enabled) {
                let analyticsHtml = '<div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">';
                analyticsHtml += '<h4 style="margin-bottom:8px;">📊 Analytics & Tracking de Mi Tienda</h4>';
                analyticsHtml += '<p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">Configura tu propio pixel para medir el tráfico hacia tus productos. Solo se activan en las páginas de tus productos.</p>';
                if (data.store.vendor_ga4_enabled) {
                    analyticsHtml += '<div class="ltms-form-group"><label>Google Analytics 4 — Measurement ID</label>';
                    analyticsHtml += '<input type="text" class="ltms-form-control" id="ltms-vendor-ga4" value="' + this.escapeHtml(data.store.vendor_ga4_id||'') + '" placeholder="G-XXXXXXXXXX">';
                    analyticsHtml += '<small style="color:#9ca3af;">Encuéntralo en Google Analytics → Admin → Flujos de datos.</small></div>';
                }
                if (data.store.vendor_pixel_enabled) {
                    analyticsHtml += '<div class="ltms-form-group"><label>Meta Pixel ID (Facebook / Instagram)</label>';
                    analyticsHtml += '<input type="text" class="ltms-form-control" id="ltms-vendor-pixel" value="' + this.escapeHtml(data.store.vendor_pixel_id||'') + '" placeholder="123456789012345">';
                    analyticsHtml += '<small style="color:#9ca3af;">Encuéntralo en Meta Business Suite → Fuentes de datos → Píxeles.</small></div>';
                }
                analyticsHtml += '<button type="button" class="ltms-btn ltms-btn-primary ltms-save-analytics-btn">💾 Guardar Analytics</button>';
                analyticsHtml += '<span class="ltms-analytics-msg" style="margin-left:10px;display:none;"></span></div>';
                document.getElementById('ltms-view-settings') && (document.getElementById('ltms-view-settings').insertAdjacentHTML('beforeend', analyticsHtml));
            }

            // Inicializar banner actual si existe
            const bannerUrl = store.store_banner_url || '';
            if (bannerUrl) {
                $('#ltms-banner-current').attr('src', bannerUrl);
                $('#ltms-banner-current-wrap').show();
            }

            // Handler: guardar configuración básica (products-ajax)
            $(document).off('click','.ltms-save-settings-btn').on('click','.ltms-save-settings-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_save_vendor_settings', nonce:ltmsDashboard.nonce,
                        store_name:$('[name="store_name"]').val(),
                        store_phone:$('[name="store_phone"]').val(),
                        store_description:$('[name="store_description"]').val(),
                        bank_info:$('[name="bank_info"]').val(),
                        settings:{
                            ltms_bank_name:$('[name="ltms_bank_name"]').val()||'',
                            ltms_bank_account_type:$('[name="ltms_bank_account_type"]').val()||'',
                            ltms_bank_account_number:$('[name="ltms_bank_account_number"]').val()||'',
                            ltms_bank_account_holder:$('[name="ltms_bank_account_holder"]').val()||'',
                        }
                    },
                    success(r) { btn.prop('disabled',false).text('💾 Guardar Cambios');
                        const m=$('.ltms-settings-msg');
                        m.text(r.success?'✓ Guardado':'Error al guardar').css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000); },
                    error(){ btn.prop('disabled',false).text('💾 Guardar Cambios'); }
                });
            });

            // Handler: guardar perfil público (ltms_save_vendor_profile — Vendor_Settings_Saver)
            $(document).off('click','.ltms-save-profile-btn').on('click','.ltms-save-profile-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{
                        action:'ltms_save_vendor_profile', nonce:ltmsDashboard.nonce,
                        ltms_store_name:$('[name="ltms_store_name"]').val(),
                        ltms_store_description:$('[name="store_description"]').val(),
                        ltms_store_city:$('[name="ltms_store_city"]').val(),
                        ltms_store_address:$('[name="ltms_store_address"]').val(),
                        ltms_store_phone:$('[name="ltms_store_phone"]').val(),
                        ltms_store_schedule:$('[name="ltms_store_schedule"]').val(),
                        ltms_store_categories:$('[name="ltms_store_categories"]').val(),
                    },
                    success(r){ btn.prop('disabled',false).text('💾 Guardar Perfil');
                        const m=$('.ltms-profile-msg');
                        m.text(r.success?'✓ Perfil guardado':'Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('💾 Guardar Perfil'); }
                });
            });

            // Handler: preview al seleccionar archivo
            $(document).off('change','#ltms-banner-file').on('change','#ltms-banner-file', function() {
                const file = this.files[0];
                if (!file) return;
                const maxMB = 3;
                if (file.size > maxMB * 1024 * 1024) {
                    const m = $('.ltms-banner-msg');
                    m.text('La imagen pesa ' + (file.size/1024/1024).toFixed(1) + ' MB. El máximo es ' + maxMB + ' MB.').css('color','#ef4444').show();
                    this.value = '';
                    $('#ltms-banner-preview-wrap').hide();
                    $('#ltms-banner-filename').hide();
                    return;
                }
                $('.ltms-banner-msg').hide();
                $('#ltms-banner-filename').text('📄 ' + file.name + ' — ' + (file.size/1024).toFixed(0) + ' KB').show();
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#ltms-banner-preview').attr('src', e.target.result);
                    $('#ltms-banner-preview-wrap').show();
                };
                reader.readAsDataURL(file);
            });

            // Handler: subir banner (ltms_upload_store_banner — Vendor_Settings_Saver)
            $(document).off('click','.ltms-upload-banner-btn').on('click','.ltms-upload-banner-btn', function() {
                const file = $('#ltms-banner-file')[0].files[0];
                const m = $('.ltms-banner-msg');
                if (!file) { m.text('Selecciona una imagen primero.').css('color','#ef4444').show(); return; }
                const btn=$(this); btn.prop('disabled',true).text('Subiendo...');
                const fd = new FormData();
                fd.append('action','ltms_upload_store_banner');
                fd.append('nonce',ltmsDashboard.nonce);
                fd.append('banner',file);
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST', data:fd,
                    processData:false, contentType:false,
                    success(r){ btn.prop('disabled',false).text('🖼️ Subir Banner');
                        m.text(r.success?'✅ Banner actualizado correctamente':'❌ Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        if (r.success && r.data && r.data.url) {
                            $('#ltms-banner-current').attr('src', r.data.url);
                            $('#ltms-banner-current-wrap').show();
                            $('#ltms-banner-file').val('');
                            $('#ltms-banner-filename').hide();
                            $('#ltms-banner-preview-wrap').hide();
                        }
                        setTimeout(()=>m.hide(),5000);
                    },
                    error(){ btn.prop('disabled',false).text('🖼️ Subir Banner');
                        m.text('❌ Error de red, intente de nuevo.').css('color','#ef4444').show();
                    }
                });
            });

            // Handler: eliminar banner (ltms_delete_store_banner — Vendor_Settings_Saver)
            $(document).off('click','.ltms-delete-banner-btn').on('click','.ltms-delete-banner-btn', function() {
                if (!confirm('¿Eliminar el banner actual? Esta acción no se puede deshacer.')) return;
                const btn=$(this); btn.prop('disabled',true).text('Eliminando...');
                const m=$('.ltms-banner-msg');
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_delete_store_banner', nonce:ltmsDashboard.nonce },
                    success(r){ btn.prop('disabled',false);
                        if (r.success) {
                            $('#ltms-banner-current-wrap').hide();
                            $('#ltms-banner-current').attr('src','');
                            m.text('✅ Banner eliminado correctamente').css('color','#10b981').show();
                        } else {
                            btn.text('🗑️ Eliminar banner');
                            m.text('❌ Error: '+(r.data||'intente de nuevo')).css('color','#ef4444').show();
                        }
                        setTimeout(()=>m.hide(),5000);
                    },
                    error(){ btn.prop('disabled',false).text('🗑️ Eliminar banner');
                        m.text('❌ Error de red.').css('color','#ef4444').show();
                    }
                });
            });

            // Handler: guardar zona de despacho (ltms_save_delivery_zone — Vendor_Settings_Saver)
            $(document).off('click','.ltms-save-zone-btn').on('click','.ltms-save-zone-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                const cities=$('#ltms-dz-cities').val().split(',').map(s=>s.trim()).filter(Boolean);
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_save_delivery_zone', nonce:ltmsDashboard.nonce,
                        cities:cities, radius_km:$('#ltms-dz-radius').val(), free_from:$('#ltms-dz-free').val() },
                    success(r){ btn.prop('disabled',false).text('💾 Guardar Zona');
                        const m=$('.ltms-zone-msg');
                        m.text(r.success?'✓ Zona guardada':'Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('💾 Guardar Zona'); }
                });
            });

            // Handler: guardar analytics del vendedor
            $(document).off('click','.ltms-save-analytics-btn').on('click','.ltms-save-analytics-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                const settings = {};
                if ($('#ltms-vendor-ga4').length) settings['ltms_vendor_ga4_id'] = $('#ltms-vendor-ga4').val();
                if ($('#ltms-vendor-pixel').length) settings['ltms_vendor_pixel_id'] = $('#ltms-vendor-pixel').val();
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_save_vendor_settings', nonce:ltmsDashboard.nonce, settings:settings },
                    success(r){ btn.prop('disabled',false).text('💾 Guardar Analytics');
                        const m=$('.ltms-analytics-msg');
                        m.text(r.success?'✅ Guardado':'❌ Error').css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('💾 Guardar Analytics'); }
                });
            });
        },
        /**
         * Carga una vista genérica como fallback.
         *
         * @param {string} view Nombre de la vista.
         */
        // ── Vista: Seguros ────────────────────────────────────────
        loadInsuranceView(forceRefresh = false) {
            const self = this;
            if (!forceRefresh && this.dataCache['insurance']) {
                this.renderInsuranceView(this.dataCache['insurance']);
                return;
            }
            $.ajax({
                url: ltmsDashboard.ajax_url, method: 'POST',
                data: { action: 'ltms_get_insurance_data', nonce: ltmsDashboard.nonce },
                success(r) {
                    const data = r.success ? r.data : { policies: [] };
                    self.dataCache['insurance'] = data;
                    self.renderInsuranceView(data);
                },
                error() { self.renderInsuranceView({ policies: [] }); }
            });
        },

        renderInsuranceView(data) {
            const policies = data.policies || [];
            let rows = '';
            if (policies.length === 0) {
                rows = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Sin pólizas registradas.</td></tr>';
            } else {
                policies.forEach(p => {
                    const statusColor = p.status === 'active' ? '#10b981' : p.status === 'claimed' ? '#f59e0b' : '#6b7280';
                    rows += `<tr>
                        <td>#${p.order_id}</td>
                        <td>${this.escapeHtml(p.insurance_type || '')}</td>
                        <td>${this.escapeHtml(p.policy_number || p.policy_id || '')}</td>
                        <td>${this.formatMoney(parseFloat(p.premium_amount || 0))}</td>
                        <td><span style="color:${statusColor};font-weight:600;">${p.status}</span></td>
                        <td>${p.certificate_url ? `<a href="${this.escapeHtml(p.certificate_url)}" target="_blank">📄 Ver</a>` : '—'}</td>
                    </tr>`;
                });
            }
            this.showSection('#ltms-view-insurance');
            $('#ltms-view-insurance').html(`
                <div class="ltms-section-header"><h2>🛡️ Mis Seguros</h2></div>
                <div class="ltms-card" style="overflow-x:auto;">
                    <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                        <thead><tr>
                            <th>Pedido</th><th>Tipo</th><th>Póliza</th>
                            <th>Prima</th><th>Estado</th><th>Certificado</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`);
        },

        // ── Vista: ReDi ───────────────────────────────────────────
        loadRediView(forceRefresh = false) {
            const self = this;
            if (!forceRefresh && this.dataCache['redi']) {
                this.renderRediView(this.dataCache['redi']);
                return;
            }
            $.ajax({
                url: ltmsDashboard.ajax_url, method: 'POST',
                data: { action: 'ltms_get_redi_data', nonce: ltmsDashboard.nonce },
                success(r) {
                    const data = r.success ? r.data : { agreements: [], available_products: [] };
                    self.dataCache['redi'] = data;
                    self.renderRediView(data);
                },
                error() { self.renderRediView({ agreements: [], available_products: [] }); }
            });
        },

        renderRediView(data) {
            const agreements = data.agreements || [];
            const available  = data.available_products || [];

            let agreementRows = agreements.length === 0
                ? '<tr><td colspan="4" style="text-align:center;padding:20px;color:#888;">Sin acuerdos activos.</td></tr>'
                : agreements.map(a => `<tr>
                    <td>${this.escapeHtml(a.origin_product_name || a.origin_product_id)}</td>
                    <td>${parseFloat(a.commission_rate || 0).toFixed(1)}%</td>
                    <td><span style="color:#10b981;font-weight:600;">${a.status}</span></td>
                    <td>
                        <button class="ltms-btn ltms-btn-sm ltms-btn-danger ltms-revoke-redi"
                            data-id="${a.id}" style="font-size:12px;">Revocar</button>
                    </td>
                </tr>`).join('');

            let availableRows = available.length === 0
                ? '<tr><td colspan="3" style="text-align:center;padding:20px;color:#888;">Sin productos disponibles.</td></tr>'
                : available.map(p => `<tr>
                    <td>${this.escapeHtml(p.post_title)}</td>
                    <td>${parseFloat(p.redi_rate || 0).toFixed(1)}%</td>
                    <td>
                        <button class="ltms-btn ltms-btn-sm ltms-btn-primary ltms-adopt-redi"
                            data-id="${p.ID}" style="font-size:12px;">Adoptar</button>
                    </td>
                </tr>`).join('');

            this.showSection('#ltms-view-redi');
            $('#ltms-view-redi').html(`
                <div class="ltms-section-header"><h2>🔁 ReDi — Productos en Reventa</h2></div>
                <div class="ltms-card" style="margin-bottom:20px;">
                    <h4 style="margin-bottom:12px;">Mis Acuerdos Activos</h4>
                    <div style="overflow-x:auto;">
                        <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                            <thead><tr><th>Producto</th><th>Comisión</th><th>Estado</th><th>Acción</th></tr></thead>
                            <tbody>${agreementRows}</tbody>
                        </table>
                    </div>
                </div>
                <div class="ltms-card">
                    <h4 style="margin-bottom:12px;">Productos Disponibles para Adoptar</h4>
                    <div style="overflow-x:auto;">
                        <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                            <thead><tr><th>Producto</th><th>Comisión</th><th>Acción</th></tr></thead>
                            <tbody>${availableRows}</tbody>
                        </table>
                    </div>
                </div>`);

            // Handler: Adoptar producto ReDi
            $(document).off('click', '.ltms-adopt-redi').on('click', '.ltms-adopt-redi', function () {
                const btn = $(this); const productId = btn.data('id');
                btn.prop('disabled', true).text('Adoptando...');
                $.ajax({
                    url: ltmsDashboard.ajax_url, method: 'POST',
                    data: { action: 'ltms_adopt_redi_product', nonce: ltmsDashboard.nonce, product_id: productId },
                    success(r) {
                        btn.prop('disabled', false).text('Adoptar');
                        if (r.success) { delete LtmsDashboard.dataCache['redi']; LtmsDashboard.loadRediView(true); }
                        else alert(r.data || 'Error al adoptar producto.');
                    },
                    error() { btn.prop('disabled', false).text('Adoptar'); }
                });
            });

            // Handler: Revocar acuerdo ReDi
            $(document).off('click', '.ltms-revoke-redi').on('click', '.ltms-revoke-redi', function () {
                if (!confirm('¿Confirmar revocación del acuerdo?')) return;
                const btn = $(this); const agreementId = btn.data('id');
                btn.prop('disabled', true).text('Revocando...');
                $.ajax({
                    url: ltmsDashboard.ajax_url, method: 'POST',
                    data: { action: 'ltms_revoke_redi_agreement', nonce: ltmsDashboard.nonce, agreement_id: agreementId },
                    success(r) {
                        btn.prop('disabled', false).text('Revocar');
                        if (r.success) { delete LtmsDashboard.dataCache['redi']; LtmsDashboard.loadRediView(true); }
                        else alert(r.data || 'Error al revocar.');
                    },
                    error() { btn.prop('disabled', false).text('Revocar'); }
                });
            });
        },

        // ── Vista: Descargas Seguras ──────────────────────────────
        loadDownloadsView(forceRefresh = false) {
            const self = this;
            if (!forceRefresh && this.dataCache['downloads']) {
                this.renderDownloadsView(this.dataCache['downloads']);
                return;
            }
            // La vista de descargas la renderiza PHP directamente; solo mostramos
            // la sección estática y un botón para generar token de descarga si hay productos digitales.
            $.ajax({
                url: ltmsDashboard.ajax_url, method: 'POST',
                data: { action: 'ltms_get_dashboard_data', section: 'downloads', nonce: ltmsDashboard.nonce },
                success(r) {
                    const downloads = r.success && r.data.downloads ? r.data.downloads : [];
                    self.dataCache['downloads'] = { downloads };
                    self.renderDownloadsView({ downloads });
                },
                error() { self.renderDownloadsView({ downloads: [] }); }
            });
        },

        renderDownloadsView(data) {
            const downloads = data.downloads || [];
            let rows = '';
            if (downloads.length === 0) {
                rows = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#888;">Sin productos digitales vendidos aún.</td></tr>';
            } else {
                downloads.forEach(d => {
                    rows += `<tr>
                        <td>#${d.order_id}</td>
                        <td>${this.escapeHtml(d.product_name || '')}</td>
                        <td>${this.escapeHtml(d.buyer_name || '')}</td>
                        <td>${this.escapeHtml(d.date || '')}</td>
                        <td>
                            <button class="ltms-btn ltms-btn-sm ltms-btn-outline ltms-gen-token"
                                data-product="${d.product_id}" data-order="${d.order_id}"
                                style="font-size:12px;">🔑 Generar Token</button>
                        </td>
                    </tr>`;
                });
            }
            this.showSection('#ltms-view-downloads');
            $('#ltms-view-downloads').html(`
                <div class="ltms-section-header"><h2>📦 Descargas Seguras</h2></div>
                <div class="ltms-card" style="overflow-x:auto;">
                    <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                        <thead><tr>
                            <th>Pedido</th><th>Producto</th><th>Comprador</th>
                            <th>Fecha</th><th>Token</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div id="ltms-token-result" style="display:none;margin-top:15px;padding:12px;background:#f0fdf4;border:1px solid #10b981;border-radius:6px;"></div>`);

            $(document).off('click', '.ltms-gen-token').on('click', '.ltms-gen-token', function () {
                const btn = $(this);
                btn.prop('disabled', true).text('Generando...');
                $.ajax({
                    url: ltmsDashboard.ajax_url, method: 'POST',
                    data: {
                        action: 'ltms_generate_download_token',
                        nonce: ltmsDashboard.nonce,
                        product_id: btn.data('product'),
                        order_id: btn.data('order'),
                    },
                    success(r) {
                        btn.prop('disabled', false).text('🔑 Generar Token');
                        if (r.success && r.data.token_url) {
                            $('#ltms-token-result')
                                .html(`✅ Token generado: <a href="${r.data.token_url}" target="_blank">${r.data.token_url}</a>`)
                                .show();
                        } else {
                            alert(r.data || 'Error al generar token.');
                        }
                    },
                    error() { btn.prop('disabled', false).text('🔑 Generar Token'); }
                });
            });
        },

        loadGenericView(view) {
            this.showSection('#ltms-view-' + view);
        },

        // ── Formatters ────────────────────────────────────────────

        /**
         * Formatea un número como moneda local.
         *
         * @param {number}  amount    Monto.
         * @param {boolean} compact   Si usar notación compacta.
         * @returns {string}
         */
        formatMoney(amount, compact = false) {
            const currency = ltmsDashboard.currency || 'COP';
            const locale   = currency === 'MXN' ? 'es-MX' : 'es-CO';
            const opts = {
                style: 'currency',
                currency,
                minimumFractionDigits: currency === 'MXN' ? 2 : 0,
            };
            if (compact) opts.notation = 'compact';
            return new Intl.NumberFormat(locale, opts).format(amount);
        },

        /**
         * Obtiene la clase CSS para el estado de un pedido.
         *
         * @param {string} status Estado del pedido.
         * @returns {string}
         */
        /**
         * Obtiene la clase CSS para el estado de un pedido.
         * P-01: incluye ready-for-pickup con su propio badge y etiquetas en español.
         *
         * @param {string} status Estado WC del pedido.
         * @returns {string}
         */
        getOrderStatusClass(status) {
            const map = {
                completed:        'ltms-badge-success',
                processing:       'ltms-badge-info',
                pending:          'ltms-badge-warning',
                cancelled:        'ltms-badge-danger',
                refunded:         'ltms-badge-pending',
                'ready-for-pickup': 'ltms-badge-pickup',
            };
            return map[status] || 'ltms-badge-pending';
        },

        /**
         * Devuelve la etiqueta legible en español para un estado de pedido.
         * P-01: evita mostrar el slug crudo (ej. "ready-for-pickup") en la tabla.
         *
         * @param {string} status Estado WC.
         * @returns {string}
         */
        getOrderStatusLabel(status) {
            const map = {
                pending:            'Pendiente',
                processing:         'Procesando',
                'ready-for-pickup': '📦 Listo para Recoger',
                completed:          'Completado',
                cancelled:          'Cancelado',
                refunded:           'Reembolsado',
                'on-hold':          'En espera',
            };
            return map[status] || status;
        },

        /**
         * Obtiene la clase CSS para el tipo de transacción.
         *
         * @param {string} type Tipo de transacción.
         * @returns {string}
         */
        getTxTypeBadge(type) {
            const map = {
                commission: 'ltms-badge-success',
                payout:     'ltms-badge-primary',
                referral:   'ltms-badge-info',
                hold:       'ltms-badge-warning',
                release:    'ltms-badge-pending',
            };
            return map[type] || 'ltms-badge-pending';
        },

        /**
         * Escapa HTML para prevenir XSS.
         *
         * @param {string} str Cadena a escapar.
         * @returns {string}
         */
        escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        },

        // ── v2.9.82 P2: Dark Mode Toggle ──────────────────────────────
        initDarkMode() {
            if (localStorage.getItem('ltms-dark-mode') === 'yes') {
                document.body.classList.add('ltms-dark-mode');
                $('#ltms-dark-mode-toggle').text('☀️');
            }
            $(document).on('click', '#ltms-dark-mode-toggle', function() {
                var isDark = document.body.classList.toggle('ltms-dark-mode');
                localStorage.setItem('ltms-dark-mode', isDark ? 'yes' : 'no');
                $(this).text(isDark ? '☀️' : '🌙');
            });
        },

        // ── v2.9.82 P2: CSV Export para Wallet ────────────────────────
        initCsvExport() {
            $(document).on('click', '#ltms-wallet-export-csv', function() {
                $.ajax({
                    url: ltmsDashboard.ajax_url,
                    method: 'POST',
                    data: { action: 'ltms_get_wallet_data', nonce: ltmsDashboard.nonce },
                    success: function(response) {
                        if (!response.success || !response.data) return;
                        var txns = response.data.transactions || [];
                        var csv = 'Fecha,Tipo,Descripcion,Monto,Balance\n';
                        txns.forEach(function(t) {
                            csv += [
                                t.created_at || '',
                                t.type || '',
                                '"' + (t.description || '').replace(/"/g, '""') + '"',
                                t.amount || 0,
                                t.balance_after || 0
                            ].join(',') + '\n';
                        });
                        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                        var link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = 'billetera_' + new Date().toISOString().slice(0,10) + '.csv';
                        link.click();
                        if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                            LTMS.UX.toastSuccess('Exportado', 'Archivo CSV descargado.');
                        }
                    },
                    error: function() {
                        if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                            LTMS.UX.toastError('Error', 'No se pudo exportar.');
                        }
                    }
                });
            });
        },

        // ── v2.9.82 P2: Breadcrumbs dinámicos ─────────────────────────
        initBreadcrumbs() {
            const self = this;
            const labels = {
                home: 'Inicio', orders: 'Pedidos', products: 'Productos',
                wallet: 'Billetera', settings: 'Configuración', envios: 'Envíos',
                'shipping-statement': 'Fletes', redi: 'ReDi', incidents: 'Novedades',
                bookings: 'Reservas', marketing: 'Marketing', security: 'Seguridad',
                donations: 'Donaciones', posgold: 'PosGold', kitchen: 'Cocina',
                'ordenes-compra': 'Órdenes de Compra', analytics: 'Analytics'
            };
            const origLoadView = this.loadView.bind(this);
            this.loadView = function(view, forceRefresh) {
                origLoadView(view, forceRefresh);
                const label = labels[view] || view;
                if ($('.ltms-breadcrumbs').length === 0) {
                    $('.ltms-topbar').after('<div class="ltms-breadcrumbs" id="ltms-breadcrumbs" style="padding:8px 24px;"></div>');
                }
                $('#ltms-breadcrumbs').html(
                    '<a href="' + ltmsDashboard.ajax_url.replace('admin-ajax.php', '') + 'panel-vendedor/">Inicio</a>' +
                    '<span>\u203a</span>' +
                    '<span>' + label + '</span>'
                );
            };
        },
    };

    // ── Inicializar cuando el DOM esté listo ─────────────────────
    $(document).ready(function () {
        if (typeof ltmsDashboard !== 'undefined' && $('#ltms-dashboard-container').length) {
            LTMS.Dashboard.init();
        }
    });

})(jQuery);
