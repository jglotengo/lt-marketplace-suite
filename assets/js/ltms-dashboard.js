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
        window.ltmsDashboardInstance = this;
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
            const btn = document.querySelector('.ltms-mobile-menu-btn');
            const updateBtnVisibility = () => {
                if (btn) btn.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
            };
            updateBtnVisibility();
            window.addEventListener('resize', updateBtnVisibility);
            const sidebar = document.querySelector('.ltms-sidebar');
            const overlay = document.querySelector('.ltms-sidebar-overlay');
            $(document).on('click', '.ltms-mobile-menu-btn', function (e) {
                e.stopPropagation();
                if (sidebar) sidebar.classList.toggle('ltms-sidebar-open');
                if (overlay) overlay.classList.toggle('active');
            });
            $(document).on('click', '.ltms-sidebar-overlay', function () {
                if (sidebar) sidebar.classList.remove('ltms-sidebar-open');
                if (overlay) overlay.classList.remove('active');
            });
            // Cerrar sidebar al navegar en móvil
            $(document).on('click', '.ltms-nav-item', function () {
                if (window.innerWidth <= 768) {
                    if (sidebar) sidebar.classList.remove('ltms-sidebar-open');
                    if (overlay) overlay.classList.remove('active');
                }
            });
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
                ? '<tr><td colspan="5" style="text-align:center;padding:20px;">Aún no tienes productos.</td></tr>'
                : products.map(p => `<tr><td style="width:60px;padding:4px;"><img src="${p.image||''}" style="width:50px;height:50px;object-fit:cover;border-radius:6px;background:#f0f0f0;" onerror="this.style.background='#e0e0e0';this.src='';" /></td><td>${this.escapeHtml(p.name)}</td><td>${this.formatMoney(p.price)}</td><td>${this.escapeHtml(p.status)}</td><td>${p.stock ?? '-'}</td><td><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
  <button class="ltms-btn ltms-btn-sm ltms-edit-product-btn" data-id="${p.id}" style="background:#1976d2;color:#fff;border:none;font-weight:600;">✏️ Editar</button>
  <button class="ltms-btn ltms-btn-sm ltms-toggle-product-btn" data-id="${p.id}" data-status="${p.status}" style="${p.status==='publish'?'background:#f59e0b;color:#fff;border:none;font-weight:600;':'background:#16a34a;color:#fff;border:none;font-weight:600;'}">${p.status==='publish'?'⏸ Pausar':'▶ Publicar'}</button>
  <button class="ltms-btn ltms-btn-sm ltms-delete-product-btn" data-id="${p.id}" data-name="${this.escapeHtml(p.name)}" style="background:transparent;color:#dc2626;border:1px solid #dc2626;font-weight:600;">🗑</button>
</div></td></tr>`).join('');
            const addUrl = (ltmsDashboard.add_product_url || '/wp-admin/post-new.php?post_type=product');
            $('#ltms-view-products').html(`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;"><h3>Mis Productos</h3><button class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn">+ Nuevo Producto</button></div><div class="ltms-table-wrap"><table class="ltms-table"><thead><tr><th style="width:60px;"></th><th>Producto</th><th>Precio</th><th>Estado</th><th>Stock</th><th>Acción</th></tr></thead><tbody>${rows}</tbody></table></div>`);
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
                            '<label style="display:block;font-weight:600;margin-bottom:5px;">Stock (dejar vacío = ilimitado)</label>' +
                            '<input type="number" id="ltms-np-stock" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" placeholder="Cantidad en stock" min="0">' +
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
                        jQuery('#ltms-np-submit, #ltms-np-draft').prop('disabled', true).text('Guardando...');
                        const ajaxUrl = (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php';
                        jQuery.ajax({ url: ajaxUrl, type: 'POST', data: {
                            action: 'ltms_create_product', nonce: nonce,
                            name: name, price: price, status: status,
                            description: jQuery('#ltms-np-desc').val(),
                            category_id: jQuery('#ltms-np-cat').val(),
                            stock: jQuery('#ltms-np-stock').val(),
                            image_id: jQuery('#ltms-np-img-id').val(),
                            gallery_ids: jQuery('#ltms-np-gallery-ids').val()
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
                        '<label style="display:block;font-weight:600;margin-bottom:5px;">Stock (vacío = ilimitado)</label>' +
                        '<input type="number" id="ltms-ep-stock" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;" value="' + (p.stock !== null ? p.stock : '') + '" min="0">' +
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
                // Guardar
                jQuery('#ltms-ep-submit').on('click', function() {
                    var name = jQuery('#ltms-ep-name').val().trim();
                    var price = jQuery('#ltms-ep-price').val();
                    if (!name || !price) {
                        jQuery('#ltms-ep-msg').show().css({background:'#fee','color':'#c00','border':'1px solid #c00'}).text('Nombre y precio son requeridos.');
                        return;
                    }
                    jQuery('#ltms-ep-submit').prop('disabled', true).text('Guardando...');
                    jQuery.ajax({ url: ajaxUrl, type: 'POST', data: {
                        action: 'ltms_update_product', nonce: nonce,
                        product_id: jQuery('#ltms-ep-product-id').val(),
                        name: name, price: price,
                        description: jQuery('#ltms-ep-desc').val(),
                        category_id: jQuery('#ltms-ep-cat').val(),
                        stock: jQuery('#ltms-ep-stock').val(),
                        image_id: jQuery('#ltms-ep-img-id').val(),
                        gallery_ids: jQuery('#ltms-ep-gallery-ids').val()
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
                    <div class="ltms-form-group"><label>Banco para Retiros</label><input type="text" class="ltms-form-control" name="bank_info" value="${this.escapeHtml(store.bank_info||'')}" placeholder="Banco / No. Cuenta"></div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-settings-btn">💾 Guardar Cambios</button>
                    <span class="ltms-settings-msg" style="margin-left:10px;display:none;"></span>
                </div>`);
            $(document).off('click','.ltms-save-settings-btn').on('click','.ltms-save-settings-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_save_vendor_settings', nonce:ltmsDashboard.nonce,
                        store_name:$('[name="store_name"]').val(), store_phone:$('[name="store_phone"]').val(),
                        store_description:$('[name="store_description"]').val(), bank_info:$('[name="bank_info"]').val() },
                    success(r) { btn.prop('disabled',false).text('💾 Guardar Cambios');
                        const m=$('.ltms-settings-msg');
                        m.text(r.success?'✓ Guardado':'Error al guardar').css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000); },
                    error(){ btn.prop('disabled',false).text('💾 Guardar Cambios'); }
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
