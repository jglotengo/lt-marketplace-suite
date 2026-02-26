/**
 * LT Marketplace Suite - Vendor Dashboard SPA
 * Panel del Vendedor - Single Page Application
 * Version: 1.5.0
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
            this.bindNavigation();
            this.loadView('home');
            this.startNotificationPolling();
            this.initMobileMenu();
            this.bindLogout();
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

                // Actualizar el título del topbar
                const title = $(this).find('.ltms-nav-label').text();
                $('.ltms-topbar-title').text(title);

                // Cerrar sidebar en móvil
                if ($(window).width() <= 768) {
                    $('.ltms-sidebar').removeClass('open');
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

            const loadMethod = 'load' + view.charAt(0).toUpperCase() + view.slice(1) + 'View';

            if (typeof this[loadMethod] === 'function') {
                this[loadMethod](forceRefresh);
            } else {
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

            // Cargar gráfica de ventas
            this.loadSalesChart();

            // Mostrar la sección
            this.showSection('#ltms-view-home');
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
         * Carga la vista de Pedidos.
         *
         * @param {boolean} forceRefresh
         */
        loadOrdersView(forceRefresh = false) {
            const self = this;

            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_orders_data',
                    nonce: ltmsDashboard.nonce,
                    page: 1,
                    per_page: 20,
                },
                success(response) {
                    if (response.success) {
                        self.renderOrdersTable(response.data.orders);
                        self.showSection('#ltms-view-orders');
                    } else {
                        self.showError('#ltms-view-orders', response.data);
                    }
                },
                error: () => self.showError('#ltms-view-orders', ltmsDashboard.i18n.error),
            });
        },

        /**
         * Renderiza la tabla de pedidos del vendedor.
         *
         * @param {Array} orders Lista de pedidos.
         */
        renderOrdersTable(orders) {
            const $tbody = $('#ltms-orders-tbody');
            $tbody.empty();

            if (!orders || orders.length === 0) {
                $tbody.append('<tr><td colspan="6" class="ltms-empty-cell">No tienes pedidos aún.</td></tr>');
                return;
            }

            orders.forEach(order => {
                const statusClass = this.getOrderStatusClass(order.status);
                $tbody.append(`
                    <tr>
                        <td>#${order.number}</td>
                        <td>${this.escapeHtml(order.customer)}</td>
                        <td>${order.items_count} item(s)</td>
                        <td><strong>${order.formatted}</strong></td>
                        <td><span class="ltms-badge ${statusClass}">${order.status}</span></td>
                        <td>${order.date}</td>
                    </tr>
                `);
            });
        },

        /**
         * Carga la vista de Billetera.
         */
        loadWalletView() {
            const self = this;

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
                        self.showSection('#ltms-view-wallet');
                    } else {
                        self.showError('#ltms-view-wallet', response.data);
                    }
                },
                error: () => self.showError('#ltms-view-wallet', ltmsDashboard.i18n.error),
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
                $tbody.append(`
                    <tr>
                        <td>${tx.created_at}</td>
                        <td>${this.escapeHtml(tx.description)}</td>
                        <td><span class="ltms-badge ${this.getTxTypeBadge(tx.type)}">${tx.type}</span></td>
                        <td class="${isCredit ? 'credit' : 'debit'}">
                            ${isCredit ? '+' : ''}${tx.formatted_amount}
                        </td>
                    </tr>
                `);
            });
        },

        /**
         * Abre el modal de solicitud de retiro.
         */
        openPayoutModal() {
            const balance = parseFloat(ltmsDashboard.wallet_balance) || 0;
            $('#ltms-payout-amount').attr('max', balance);
            $('#ltms-payout-balance-display').text(this.formatMoney(balance));
            LTMS.Modal.open('ltms-modal-payout');
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
                        this.showToast('error', response.data);
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
                    if (response.success && response.data.count > 0) {
                        this.updateNotificationBadge(response.data.count);
                        this.renderNotifications(response.data.notifications);
                        if (response.data.notifications.length > 0) {
                            this.lastNotifDate = response.data.notifications[0].created_at;
                        }
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
        renderNotifications(notifications) {
            const $list = $('#ltms-notif-list');
            $list.empty();

            notifications.forEach(notif => {
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
            $(selector).html(`<div class="ltms-notice ltms-notice-error"><p>${this.escapeHtml(message)}</p></div>`).show();
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
            $(document).on('click', '.ltms-mobile-menu-btn', function () {
                $('.ltms-sidebar').toggleClass('open');
            });

            $(document).on('click', '.ltms-sidebar-overlay', function () {
                $('.ltms-sidebar').removeClass('open');
            });
        },

        /**
         * Vincula el botón de logout.
         */
        bindLogout() {
            $(document).on('click', '.ltms-logout-btn', (e) => {
                e.preventDefault();
                $.ajax({
                    url: ltmsDashboard.ajax_url,
                    method: 'POST',
                    data: { action: 'ltms_vendor_logout', nonce: ltmsDashboard.nonce },
                    success(response) {
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        }
                    },
                });
            });
        },

        /**
         * Carga una vista genérica como fallback.
         *
         * @param {string} view Nombre de la vista.
         */
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
        getOrderStatusClass(status) {
            const map = {
                completed:  'ltms-badge-success',
                processing: 'ltms-badge-info',
                pending:    'ltms-badge-warning',
                cancelled:  'ltms-badge-danger',
                refunded:   'ltms-badge-pending',
            };
            return map[status] || 'ltms-badge-pending';
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
    };

    // ── Inicializar cuando el DOM esté listo ─────────────────────
    $(document).ready(function () {
        if (typeof ltmsDashboard !== 'undefined' && $('#ltms-dashboard-container').length) {
            LTMS.Dashboard.init();
        }
    });

})(jQuery);
