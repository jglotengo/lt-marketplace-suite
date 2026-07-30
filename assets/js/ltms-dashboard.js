/**
 * LT Marketplace Suite - Vendor Dashboard SPA
 * Panel del Vendedor - Single Page Application
 * Version: 1.5.2
 */

/* global ltmsDashboard, jQuery, Chart */

(function ($) {
    'use strict';

    // â”€â”€ Namespace del Dashboard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        /** Ãšltima fecha de notificaciÃ³n recibida */
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
            this.initGlobalSearch();    // v2.9.84 P1
            this.initKeyboardShortcuts(); // v2.9.91 P3
            this.initNonceRefresh();    // FIX-403-NONCE
        },

        /**
         * FIX-403-NONCE: mantiene ltmsDashboard.nonce vivo mientras el panel
         * estÃ¡ abierto, vÃ­a WP Heartbeat. Sin esto, el nonce generado al
         * cargar la pÃ¡gina vence tras ~12-24h y toda llamada AJAX posterior
         * falla con 403 â€” tÃ­pico en sesiones largas sin recargar (cuentas
         * operadas por un asistente/agente que no cierra la pestaÃ±a).
         *
         * Doble mecanismo:
         * 1) Heartbeat: en cada tick, pide al servidor un nonce fresco y
         *    actualiza ltmsDashboard.nonce en memoria (sin recargar nada).
         * 2) Respaldo: si a pesar de esto una llamada AJAX del dashboard
         *    recibe 403, se fuerza una recarga completa de la pÃ¡gina (con
         *    candado de 60s para no entrar en loop de recargas).
         */
        initNonceRefresh() {
            if (typeof jQuery === 'undefined' || typeof ltmsDashboard === 'undefined') {
                return;
            }

            // 1) Refresco proactivo vÃ­a polling propio.
            //
            // FIX-403-NONCE-2: WP Heartbeat (heartbeat-send/heartbeat-tick)
            // NO es confiable en este hosting â€” SiteGround Optimizer
            // desregistra wp_ajax_heartbeat, asÃ­ que el propio script
            // heartbeat.js de WP Core dispara action=heartbeat contra
            // ?ltms_ajax=1 y el router custom lo rechaza con 400 en cada
            // tick (ver Network tab: POST ?ltms_ajax=1 400 en bucle).
            // En vez de depender del Heartbeat de WP, usamos nuestro propio
            // endpoint AJAX (ltms_refresh_dashboard_nonce), que sÃ­ pasa por
            // el router custom sin problema porque lo registramos nosotros.
            $.post(ltmsDashboard.ajax_url, {
                action: 'ltms_refresh_dashboard_nonce',
                nonce: ltmsDashboard.nonce
            }); // ping inicial, no crÃ­tico si falla â€” el intervalo abajo reintenta.

            setInterval(function () {
                $.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_refresh_dashboard_nonce',
                    nonce: ltmsDashboard.nonce
                }).done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.nonce) {
                        ltmsDashboard.nonce = resp.data.nonce;
                    }
                });
            }, 600000); // cada 10 min â€” de sobra frente a la vida Ãºtil del nonce (~12-24h).

            // 2) Respaldo: recarga forzada si un 403 se cuela de todas formas.
            var lastReloadAttempt = 0;
            $(document).ajaxError(function (event, jqXHR, ajaxSettings) {
                var isDashboardCall = typeof ltmsDashboard !== 'undefined' &&
                    ajaxSettings && ajaxSettings.url === ltmsDashboard.ajax_url;

                if (!isDashboardCall || jqXHR.status !== 403) {
                    return;
                }

                var now = Date.now();
                if (now - lastReloadAttempt < 60000) {
                    return; // candado de 60s: evita loop de recargas.
                }
                lastReloadAttempt = now;

                window.location.reload();
            });
        },

        /**
         * Vincula los eventos de navegaciÃ³n del sidebar.
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

                // Actualizar el tÃ­tulo del topbar
                const title = $(this).find('.ltms-nav-label').text();
                $('.ltms-topbar-title').text(title);

                // Cerrar sidebar en mÃ³vil
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

            // v2.9.84 P1: Delegated handlers for data-action (CSP compliance â€” no inline onclick).
            $(document).on('click', '[data-action]', function(e) {
                e.preventDefault();
                var action = $(this).data('action');
                var view = $(this).data('view');
                var refresh = $(this).data('refresh');
                switch (action) {
                    case 'load-view':
                        self.loadView(view, refresh === '1' || refresh === 1);
                        break;
                    case 'open-payout':
                        if (typeof self.openPayoutModal === 'function') self.openPayoutModal();
                        else $('[data-ltms-modal-open="ltms-modal-payout"]').click();
                        break;
                    case 'submit-payout':
                        if (typeof self.submitPayoutRequest === 'function') self.submitPayoutRequest();
                        break;
                    case 'close-notif-panel':
                        $('.ltms-notifications-panel').removeClass('open');
                        $('#ltms-notif-bell').attr('aria-expanded', 'false');
                        break;
                    case 'copy-referral':
                        var code = $(this).prev('input').val() || $(this).closest('.ltms-form-group').find('input').val() || '';
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(code).then(() => {
                                $(this).text('âœ“ Copiado!');
                                setTimeout(() => { $(this).text('Copiar'); }, 2000);
                            });
                        }
                        break;
                }
            });
        },

        /**
         * Carga y renderiza una vista del SPA.
         *
         * @param {string} view Nombre de la vista.
         * @param {boolean} forceRefresh Forzar recarga ignorando cachÃ©.
         */
        loadView(view, forceRefresh = false) {
            this.currentView = view;

            // AUDIT-PANEL-FN-10 (re-auditoría): scope el selector al contenedor del SPA en vez
            // del global. La version anterior cerraba TODOS los nodos con la clase .ltms-view-section
            // — incluyendo cualquier markup externo al dashboard (otro plugin, theme) que reusara la
            // clase. Tambien afectaba al nested duplicado (ver FN-09).
            $('#ltms-dashboard-container .ltms-view-section').hide();

            // v2.9.110 FIX: Mostrar la secciÃ³n INMEDIATAMENTE antes del AJAX.
            // Esto garantiza que el contenido PHP renderizado sea visible incluso
            // si el AJAX falla (403, 500, etc.). El AJAX solo actualiza datos
            // dinÃ¡micos dentro de la secciÃ³n, no la reemplaza.
            this.showSection('#ltms-view-' + view);

            // v2.9.75 FIX: Normalizar el nombre del view para construir el mÃ©todo.
            const normalized = view.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('');
            const loadMethod = 'load' + normalized + 'View';

            if (typeof this[loadMethod] === 'function') {
                this[loadMethod](forceRefresh);
            }
            // Si no hay mÃ©todo especÃ­fico, la secciÃ³n ya estÃ¡ visible arriba.
        },

        /**
         * Carga la vista Home con mÃ©tricas y grÃ¡ficas.
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
                        // FIX-NAN-HOME: cuando el perfil estÃ¡ incompleto (ej. registro
                        // vÃ­a Google OAuth), el servidor responde success:true pero SIN
                        // monthly_sales/monthly_orders/monthly_commissions/wallet_balance
                        // â€” solo trae profile_incomplete + redirect (ver UX-06 en
                        // ajax_get_dashboard_data()). Antes esto se pasaba directo a
                        // renderHomeView(), que pintaba "NaN" en las 3 mÃ©tricas porque
                        // nunca se revisaba este flag ni se ejecutaba el redirect.
                        if (response.data && response.data.profile_incomplete) {
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            }
                            return;
                        }
                        self.dataCache[cacheKey] = response.data;
                        self.renderHomeView(response.data);
                    } else {
                        // v2.9.110: No mostrar error â€” la vista PHP ya estÃ¡ visible.
                        // FASE2B P1 FIX: removed console.log â€” production JS should not
                        // leak internal AJAX responses to browser console.
                    }
                },
                error: () => {
                    // v2.9.110: No mostrar error â€” la vista PHP ya estÃ¡ visible.
                    // FASE2B P1 FIX: removed console.log â€” expected error, silent fail.
                },
            });
        },

        /**
         * Renderiza la vista Home con los datos obtenidos.
         *
         * @param {Object} data Datos del dashboard.
         */
        renderHomeView(data) {
            // Actualizar mÃ©tricas
            this.updateMetric('.ltms-metric-sales', data.monthly_sales, true);
            this.updateMetric('.ltms-metric-orders', data.monthly_orders, false);
            this.updateMetric('.ltms-metric-commissions', data.monthly_commissions, true);
            this.updateMetric('.ltms-metric-balance', data.wallet_balance, true);

            // M-AUDIT-REG-07: banner de onboarding (puramente informativo, no bloquea nada).
            this.renderOnboardingBanner(data.onboarding);

            // Cargar grÃ¡fica de ventas
            this.loadSalesChart();

            // v2.9.90 P2: Cargar widgets adicionales
            this.loadHomeWidgets();

            // Mostrar la secciÃ³n
            this.showSection('#ltms-view-home');
        },

        /**
         * v2.9.90 P2: Carga widgets de pedidos recientes y top productos en home.
         */
        loadHomeWidgets() {
            const self = this;

            // Pedidos recientes (reutiliza el endpoint de orders con page=1, per_page=5)
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: { action: 'ltms_get_orders_data', nonce: ltmsDashboard.nonce, page: 1, per_page: 5 },
                success: function(response) {
                    if (!response.success || !response.data || !response.data.orders) return;
                    var orders = response.data.orders;
                    if (!orders.length) {
                        $('#ltms-home-recent-orders').html(
                            '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:0.85rem;">' +
                            'No tienes pedidos todavÃ­a</div>'
                        );
                        return;
                    }
                    var html = orders.map(function(o) {
                        var statusColors = {
                            'pending': '#f59e0b', 'processing': '#3b82f6',
                            'completed': '#10b981', 'cancelled': '#ef4444',
                            'ready-for-pickup': '#8b5cf6'
                        };
                        var color = statusColors[o.status] || '#6b7280';
                        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f3f4f6;">' +
                            '<div>' +
                                '<div style="font-weight:600;font-size:0.82rem;color:#111827;">#' + o.number + '</div>' +
                                '<div style="font-size:0.72rem;color:#9ca3af;">' + (o.customer || 'Cliente') + '</div>' +
                            '</div>' +
                            '<div style="text-align:right;">' +
                                '<div style="font-weight:600;font-size:0.82rem;">' + self.escapeHtml(o.formatted || '') + '</div>' +
                                '<div style="font-size:0.68rem;color:' + color + ';text-transform:capitalize;">' + self.escapeHtml(o.status || '') + '</div>' +
                            '</div>' +
                        '</div>';
                    }).join('');
                    $('#ltms-home-recent-orders').html(html);
                },
                error: function() {
                    $('#ltms-home-recent-orders').html('<div style="text-align:center;padding:20px;color:#9ca3af;font-size:0.85rem;">Error al cargar</div>');
                }
            });

            // Top productos (reutiliza el endpoint de products con page=1, per_page=5)
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: { action: 'ltms_get_products_data', nonce: ltmsDashboard.nonce, page: 1, per_page: 5 },
                success: function(response) {
                    if (!response.success || !response.data || !response.data.products) return;
                    var products = response.data.products;
                    if (!products.length) {
                        $('#ltms-home-top-products').html(
                            '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:0.85rem;">' +
                            'No tienes productos todavÃ­a</div>'
                        );
                        return;
                    }
                    var html = products.map(function(p, i) {
                        var medal = i === 0 ? 'ðŸ¥‡' : i === 1 ? 'ðŸ¥ˆ' : i === 2 ? 'ðŸ¥‰' : (i + 1);
                        // FASE2B P1 FIX (XSS): escape p.name and p.image from AJAX response.
                        var safeName = self.escapeHtml(p.name || '');
                        var safeImage = p.image ? self.escapeHtml(p.image) : '';
                        return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;">' +
                            '<span style="font-size:1rem;width:24px;text-align:center;">' + medal + '</span>' +
                            (safeImage ? '<img src="' + safeImage + '" style="width:36px;height:36px;border-radius:6px;object-fit:cover;">' : '<div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:0.7rem;color:#9ca3af;">ðŸ“¦</div>') +
                            '<div style="flex:1;min-width:0;">' +
                                '<div style="font-weight:600;font-size:0.82rem;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + safeName + '</div>' +
                                '<div style="font-size:0.72rem;color:#9ca3af;">' + (p.stock !== null ? 'Stock: ' + p.stock : 'Sin stock') + '</div>' +
                            '</div>' +
                            '<div style="font-weight:600;font-size:0.82rem;color:#10b981;">$' + (p.price || 0).toLocaleString('es-CO') + '</div>' +
                        '</div>';
                    }).join('');
                    $('#ltms-home-top-products').html(html);
                },
                error: function() {
                    $('#ltms-home-top-products').html('<div style="text-align:center;padding:20px;color:#9ca3af;font-size:0.85rem;">Error al cargar</div>');
                }
            });
        },

        /**
         * Renderiza (o esconde) el banner de checklist de onboarding del vendedor.
         * No bloquea ninguna acciÃ³n â€” solo informa quÃ© pasos faltan.
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
                pending: { text: t('kyc_pending', 'En revisiÃ³n'), color: '#f59e0b' },
                approved: { text: t('kyc_approved', 'Aprobado'), color: '#10b981' },
                rejected: { text: t('kyc_rejected', 'Rechazado â€” corrige y reenvÃ­a'), color: '#ef4444' },
                expired: { text: t('kyc_expired', 'Expirado â€” renueva'), color: '#6b7280' },
            };
            const kyc = kycLabels[ob.kyc_status] || kycLabels.none;
            const kycDone = ob.kyc_status === 'approved';

            const steps = [
                {
                    done: !!ob.email_verified,
                    icon: 'âœ‰ï¸',
                    title: t('ob_email_title', 'Verifica tu email'),
                    detail: ob.email_verified ? t('ob_email_done', 'Verificado') : t('ob_email_pending', 'Revisa tu bandeja de entrada (y spam) para confirmar tu cuenta.'),
                    action: null,
                },
                {
                    done: kycDone,
                    icon: 'ðŸªª',
                    title: t('ob_kyc_title', 'Completa tu verificaciÃ³n de identidad (KYC)'),
                    detail: kyc.text,
                    action: kycDone ? null : { label: t('ob_kyc_action', 'Completar KYC'), url: ob.kyc_url },
                },
                {
                    done: !!ob.store_configured,
                    icon: 'ðŸª',
                    title: t('ob_store_title', 'Configura tu tienda'),
                    detail: ob.store_configured ? t('ob_store_done', 'Tienda configurada') : t('ob_store_pending', 'AÃ±ade logo, descripciÃ³n y banner a tu tienda.'),
                    action: ob.store_configured ? null : { label: t('ob_store_action', 'Configurar tienda'), view: 'settings' },
                },
                {
                    done: !!ob.has_products,
                    icon: 'ðŸ›ï¸',
                    title: t('ob_product_title', 'Publica tu primer producto'),
                    detail: ob.has_products ? t('ob_product_done', 'Ya tienes productos publicados') : t('ob_product_pending', 'Tu tienda aÃºn no tiene productos visibles.'),
                    action: ob.has_products ? null : { label: t('ob_product_action', 'Agregar producto'), view: 'products' },
                },
            ];

            const stepsHtml = steps.map((s, i) => `
                <div style="display:flex;align-items:center;gap:12px;padding:10px 0;${i < steps.length - 1 ? 'border-bottom:1px solid #e5e7eb;' : ''}">
                    <span style="font-size:1.3rem;flex-shrink:0;">${s.done ? 'âœ…' : s.icon}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:.9rem;color:#111827;${s.done ? 'text-decoration:line-through;color:#9ca3af;' : ''}">${s.title}</div>
                        <div style="font-size:.8rem;color:#6b7280;">${s.detail}</div>
                    </div>
                    ${s.action ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-onboarding-action" data-view="${s.action.view || ''}" data-url="${s.action.url || ''}">${s.action.label}</button>` : ''}
                </div>
            `).join('');

            $banner.html(`
                <div class="ltms-card" style="padding:24px;border-left:4px solid #2563eb;background:#fff;">
                    <div style="font-weight:800;font-size:1.15rem;color:#111827;margin-bottom:6px;">
                        ðŸ‘‹ ${t('ob_welcome', 'Â¡Bienvenido a Lo Tengo!')}
                    </div>
                    <div style="font-size:.9rem;color:#374151;margin-bottom:16px;line-height:1.5;">
                        ${t('ob_subtitle', 'Para habilitar tu tienda y empezar a vender, completa estos 4 pasos en orden. Cada paso desbloquea el siguiente.')}
                    </div>
                    <div style="background:#eff6ff;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.82rem;color:#1e40af;font-weight:600;">
                        â³ Tiempo estimado: 10-15 minutos Â· Si tienes dudas, contacta soporte@lo-tengo.com.co
                    </div>
                    ${stepsHtml}
                    <div style="margin-top:16px;padding:12px 16px;background:#f9fafb;border-radius:8px;font-size:.8rem;color:#6b7280;line-height:1.5;">
                        <strong>Â¿QuÃ© pasa despuÃ©s?</strong> Una vez completados los 4 pasos, nuestro equipo revisa tu informaciÃ³n (1-2 dÃ­as hÃ¡biles). RecibirÃ¡s un email cuando tu tienda estÃ© 100% habilitada para vender.
                    </div>
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
         * Carga la grÃ¡fica de ventas del vendedor.
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
         * AUDIT-PANEL-FN-09 (re-auditoría): cargador dedicado para la vista Analytics del nav.
         * Antes el tab "Analytics" no tenía `loadAnalyticsView()` (el router solo hacía
         * `showSection`), y `loadSalesChart()` buscaba el canvas del home 
         * (`ltms-vendor-sales-chart`) — dejando el canvas `ltms-vendor-analytics-chart`
         * en blanco para siempre. Este handler llama a `renderAnalyticsChart()` que
         * renderiza sobre el canvas correcto de la vista analytics.
         */
        loadAnalyticsView(forceRefresh = false) {
            this.renderAnalyticsChart();
        },

        /**
         * AUDIT-PANEL-FN-09: renderiza el chart sobre el canvas de la vista Analytics.
         * Mismo endpoint (`ltms_get_analytics_data`) y mismo dataset que `loadSalesChart`,
         * pero sobre `<canvas id="ltms-vendor-analytics-chart">`. Reutiliza this.charts.analytics
         * (en vez de this.charts.sales) para no destruir el chart del home al volver.
         */
        renderAnalyticsChart() {
            const canvas = document.getElementById('ltms-vendor-analytics-chart');
            if (!canvas || typeof Chart === 'undefined') return;

            // Destruir instancia anterior si existe.
            if (this.charts.analytics) {
                this.charts.analytics.destroy();
            }
            if (!this.charts) this.charts = {};

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

                    this.charts.analytics = new Chart(canvas, {
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
                            ],
                        },
                    });
                },
            });
        },

        /**
         * Estado de la vista de Pedidos: pÃ¡gina/filtro actuales, para que
         * paginaciÃ³n y filtro trabajen sobre el mismo estado en vez de
         * mandar siempre page:1 (bug de la versiÃ³n anterior).
         */
        ordersState: { page: 1, perPage: 20, status: '', totalPages: 1, dateFilter: '', search: '' },

        /**
         * Carga la vista de Pedidos (primera carga / al navegar al tab).
         */
        loadOrdersView() {
            this.ordersState = { page: 1, perPage: 20, status: '', totalPages: 1, dateFilter: '', search: '' };
            this.fetchOrders();
        },

        /**
         * Hace la peticiÃ³n AJAX real usando el estado actual (pÃ¡gina/filtro)
         * y renderiza tabla + controles de paginaciÃ³n.
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
                    date_filter: self.ordersState.dateFilter || '',
                    search: self.ordersState.search || '',
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
         * P-01: columna de tipo de envÃ­o, badge pickup diferenciado, etiquetas en espaÃ±ol.
         * audit-pedidos: filas clicables que abren el modal de detalle.
         *
         * @param {Array} orders Lista de pedidos.
         */
        renderOrdersTable(orders) {
            const $tbody = $('#ltms-orders-tbody');
            $tbody.empty();

            if (!orders || orders.length === 0) {
                $tbody.append('<tr><td colspan="7" class="ltms-empty-cell">No tienes pedidos aÃºn.</td></tr>');
                return;
            }

            orders.forEach(order => {
                const statusClass = this.getOrderStatusClass(order.status);
                const statusLabel = this.getOrderStatusLabel(order.status);

                // Columna de tipo de envÃ­o: Ã­cono de tienda para pickup, texto normal para el resto
                let shippingCell = '';
                if (order.is_pickup) {
                    const addr = order.store_info && order.store_info.address
                        ? `<div style="font-size:.75rem;color:#6b7280;margin-top:2px;">${this.escapeHtml(order.store_info.address)}</div>`
                        : '';
                    shippingCell = `<span style="color:#1a5276;font-weight:600;">ðŸª Recogida</span>${addr}`;
                } else {
                    shippingCell = `<span style="color:#6b7280;font-size:.85rem;">${this.escapeHtml(order.shipping_label || 'â€”')}</span>`;
                }

                $tbody.append(`
                    <tr class="ltms-order-row" data-order-id="${order.id}" style="cursor:pointer;" tabindex="0" role="button" aria-label="Ver detalle del pedido #${order.number}">
                        <td>#${order.number}</td>
                        <td>${this.escapeHtml(order.customer)}</td>
                        <td>${order.items_count} item(s)</td>
                        <td><strong>${order.formatted}</strong></td>
                        <td>${shippingCell}</td>
                        <td><span class="ltms-badge ${statusClass}">${statusLabel}</span></td>
                        <td>${order.is_redi ? '<span style="background:#E80001;color:#fff;padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:600;">ReDi ' + (order.redi_role === 'origin' ? 'ðŸ“' : 'ðŸ”') + '</span>' : '<span style="color:#ccc;">â€”</span>'}</td>
                        <td>${order.date}</td>
                    </tr>
                `);
            });
        },

        /**
         * Renderiza los controles de paginaciÃ³n (Anterior / pÃ¡gina X de Y / Siguiente)
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
                <span>PÃ¡gina ${page} de ${totalPages} Â· ${total} pedido(s)</span>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-orders-prev" ${page <= 1 ? 'disabled' : ''} aria-label="PÃ¡gina anterior">â€¹ Anterior</button>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-orders-next" ${page >= totalPages ? 'disabled' : ''} aria-label="PÃ¡gina siguiente">Siguiente â€º</button>
                </div>
            `);
        },

        /**
         * Carga la vista de Billetera.
         */
        loadWalletView() {
            const self = this;

            // v2.9.75: Mostrar la secciÃ³n inmediatamente, antes del AJAX.
            // AsÃ­, si el AJAX falla, el vendor aÃºn ve la estructura de la vista
            // (aunque sin datos dinÃ¡micos) en vez de quedarse en "Cargando...".
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
                    // No mostrar error â€” la vista ya estÃ¡ visible con datos estÃ¡ticos.
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
                const displayAmount = tx.formatted || tx.formatted_amount || tx.amount || 'â€”';
                // C5-5 fix: handler devuelve tx.date (no tx.created_at)
                const displayDate = tx.date || tx.created_at || 'â€”';
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
                // Usar LTMS.Modal si estÃ¡ disponible, sino mostrar directamente
                if (typeof LTMS.Modal !== 'undefined' && typeof LTMS.Modal.open === 'function') {
                    LTMS.Modal.open('ltms-modal-payout');
                } else {
                    $modal.addClass('ltms-modal-open');
                    $('body').addClass('ltms-modal-body-lock');
                }
            };
            // Si el modal no estÃ¡ en el DOM, navegar a wallet primero
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
         * EnvÃ­a la solicitud de retiro.
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
                        // M-123 FIX: response.data puede ser objeto o string â€” extraer mensaje seguro
                        const errMsg = (typeof response.data === 'string')
                            ? response.data
                            : (response.data?.message || ltmsDashboard.i18n.error);
                        this.showToast('error', errMsg);
                    }
                },
            });
        },

        // â”€â”€ Notificaciones â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
         * Obtiene notificaciones no leÃ­das del servidor.
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

                    // M-15 FIX: usar `count` (total real no leÃ­das) para el badge SIEMPRE,
                    // independientemente de si hay nuevas. Esto permite que el badge se
                    // ponga en 0 cuando el vendedor marca todo como leÃ­do.
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
         * @param {number} count NÃºmero de notificaciones.
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
         * Marca una notificaciÃ³n como leÃ­da.
         *
         * @param {number} id    ID de la notificaciÃ³n.
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

        // â”€â”€ UI Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        /**
         * Muestra una secciÃ³n del SPA.
         *
         * @param {string} selector Selector CSS de la secciÃ³n.
         */
        showSection(selector) {
            // AUDIT-PANEL-FN-10: scope loader hide al contenedor del SPA (evita colisiones).
            $('#ltms-dashboard-container .ltms-view-loader').hide();
            $(selector).show();
        },

        /**
         * Muestra un loader mientras carga la vista.
         */
        showViewLoader() {
            // AUDIT-PANEL-FN-10: scoped al contenedor del SPA.
            if ($('#ltms-dashboard-container .ltms-view-loader').length === 0) {
                $('<div class="ltms-view-loader" style="text-align:center;padding:40px;color:#888;">Cargando...</div>')
                    .appendTo('#ltms-main-content');
            }
            $('#ltms-dashboard-container .ltms-view-loader').show();
        },

        /**
         * Muestra un mensaje de error en una secciÃ³n.
         *
         * @param {string} selector Selector CSS.
         * @param {string} message  Mensaje de error.
         */
        showError(selector, message) {
            // AUDIT-PANEL-FN-10: scoped al contenedor del SPA.
            $('#ltms-dashboard-container .ltms-view-loader').hide();
            // M-123 FIX: message puede ser objeto cuando viene de wp_send_json_error
            const msg = (typeof message === 'string') ? message
                : (message?.message || message?.data || ltmsDashboard?.i18n?.error || 'Error');
            $(selector).html(`<div class="ltms-notice ltms-notice-error"><p>${this.escapeHtml(msg)}</p></div>`).show();
        },

        /**
         * Muestra una notificaciÃ³n tipo toast.
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
         * Actualiza el valor de una mÃ©trica con animaciÃ³n.
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
         * Inicializa el menÃº mÃ³vil (toggle del sidebar).
         */
        initMobileMenu() {
            if (window.innerWidth > 768) return;

            const sidebar = document.querySelector('.ltms-sidebar');
            const overlay = document.querySelector('.ltms-sidebar-overlay');
            const topbar  = document.querySelector('.ltms-topbar');
            const main    = document.querySelector('.ltms-main-content');
            const TOPBAR_H = 52;

            // AUD-02 FIX: botÃ³n "MÃ¡s" en bottom-nav abre el sidebar completo
            // en mÃ³vil, dando acceso a las ~17 vistas no listadas en la
            // bottom-nav de 5 items.
            $(document).on('click', '.ltms-bottom-nav-item[data-action="open-sidebar"]', function(e) {
                e.preventDefault();
                if (sidebar) {
                    sidebar.classList.add('ltms-sidebar-open');
                    if (overlay) overlay.style.display = 'block';
                }
            });

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
                if (menuBtn) Object.assign(menuBtn.style, { background: 'var(--ltms-primary, #E80001)', color: '#fff', border: 'none' });
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

            const open  = () => { if (sidebar) { sidebar.classList.add('ltms-sidebar-open'); sidebar.style.display = 'block'; } if (overlay) { overlay.classList.add('active'); overlay.style.display = 'block'; } document.body.style.overflow = 'hidden'; };
            const close = () => { if (sidebar) { sidebar.classList.remove('ltms-sidebar-open'); } if (overlay) { overlay.classList.remove('active'); overlay.style.display = 'none'; } document.body.style.overflow = ''; };

            // v2.9.280 FIX: hamburguesa no funciona al primer click.
            // Root cause: el handler delegado no disparaba si el sidebar
            // no tenÃ­a display:block inicialmente. Fix: usar handler directo
            // en el botÃ³n + toggle display explÃ­cito.
            const menuBtnEl = document.querySelector('.ltms-mobile-menu-btn');
            if (menuBtnEl) {
                menuBtnEl.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (sidebar && sidebar.classList.contains('ltms-sidebar-open')) {
                        close();
                    } else {
                        open();
                    }
                });
            }
            // Mantener el delegado como fallback
            $(document).on('click', '.ltms-mobile-menu-btn', e => { e.stopPropagation(); });
            $(document).on('click', '.ltms-sidebar-overlay', close);
            $(document).on('click', '.ltms-sidebar-close-btn', close);
            $(document).on('click', '.ltms-nav-item', () => close());
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        },

        /**
         * Vincula el botÃ³n de logout.
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

            // v2.9.89 P2: Date range filter
            $(document).on('change', '#ltms-order-date-filter', function () {
                self.ordersState.dateFilter = $(this).val();
                self.ordersState.page = 1;
                self.fetchOrders();
            });

            // AUDIT-PANEL-FN-07 (re-auditoría): handler 'input' para el campo de búsqueda
            // de pedidos. Antes el estado `ordersState.search` existía y se enviaba al server
            // (línea 601 en fetchOrders), PERO ningún handler lo poblaba — escribir en
            // #ltms-order-search no disparaba ninguna query AJAX. La única forma de poblarlo
            // era via initGlobalSearch (líneas 2189-2212) que triggera un input event que nada
            // escuchaba. Agregado handler con debounce 300ms que actualiza search + reset page.
            let searchTimer = null;
            $(document).on('input', '#ltms-order-search', function () {
                if (searchTimer) clearTimeout(searchTimer);
                const q = $(this).val().trim();
                searchTimer = setTimeout(function () {
                    self.ordersState.search = q;
                    self.ordersState.page = 1;
                    self.fetchOrders();
                }, 300);
            });

            // PaginaciÃ³n
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

            // Fila clicable â†’ abre modal de detalle (clic o Enter/Espacio por accesibilidad)
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

            // v2.9.222: Generar factura electrÃ³nica desde el detalle del pedido
            $(document).on('click', '#ltms-generate-invoice-btn', function () {
                const orderId = $(this).data('order-id');
                const $btn = $(this);
                const originalText = $btn.html();
                $btn.prop('disabled', true).html('â³ Generando...');

                $.ajax({
                    url: ltmsDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ltms_vendor_generate_invoice',
                        nonce: ltmsDashboard.nonce,
                        order_id: orderId,
                    },
                    success: function (response) {
                        if (response.success) {
                            const data = response.data;
                            // Reemplazar el botÃ³n con el badge de factura generada.
                            $btn.replaceWith(`
                                <div style="display:inline-flex;align-items:center;gap:8px;background:#D1FAE5;color:#065F46;padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:600;">
                                    âœ… Factura ${data.provider.toUpperCase()} #${self.escapeHtml(data.invoice_number)}
                                </div>
                            `);
                            // Toast de Ã©xito.
                            if (typeof LTMS.UX !== 'undefined' && LTMS.UX.toast) {
                                LTMS.UX.toast('success', 'Factura generada', data.message, { duration: 4000 });
                            } else {
                                alert(data.message);
                            }
                            // Recargar el detalle para sincronizar.
                            setTimeout(function () {
                                self.openOrderDetail(orderId);
                            }, 1500);
                        } else {
                            $btn.prop('disabled', false).html(originalText);
                            const msg = (response.data && response.data.message) || 'Error al generar factura.';
                            if (typeof LTMS.UX !== 'undefined' && LTMS.UX.toast) {
                                LTMS.UX.toast('error', 'Error', msg, { duration: 5000 });
                            } else {
                                alert(msg);
                            }
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html(originalText);
                        if (typeof LTMS.UX !== 'undefined' && LTMS.UX.toast) {
                            LTMS.UX.toast('error', 'Error', 'No se pudo conectar con el servidor.', { duration: 5000 });
                        } else {
                            alert('No se pudo conectar con el servidor.');
                        }
                    },
                });
            });
        },

        /**
         * Abre el modal de detalle de un pedido y carga su informaciÃ³n completa.
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
                       ðŸª <strong>Recogida en tienda</strong><br>${this.escapeHtml(d.store_info.address)}
                   </div>`
                : '';

            const transitionsHtml = (d.allowed_transitions || []).map(status => {
                const label = this.getOrderStatusLabel(status);
                return `<button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-order-status-action" data-status="${status}">${label}</button>`;
            }).join(' ');

            const notesHtml = (d.notes || []).length
                ? d.notes.map(n => `<div style="font-size:.8rem;color:#6b7280;border-left:2px solid #e5e7eb;padding-left:8px;margin-bottom:6px;">${this.escapeHtml(n.content)} <span style="color:#9ca3af;">Â· ${n.date}</span></div>`).join('')
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
                        <strong>EnvÃ­o</strong><br>
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
                        <tr><td colspan="2" style="text-align:right;color:#6b7280;">EnvÃ­o</td><td style="text-align:right;">${d.shipping_total}</td></tr>
                        <tr><td colspan="2" style="text-align:right;font-weight:700;">Total</td><td style="text-align:right;font-weight:700;">${d.total}</td></tr>
                    </tfoot>
                </table>

                ${d.customer_note ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;font-size:.85rem;"><strong>Nota del cliente:</strong> ${this.escapeHtml(d.customer_note)}</div>` : ''}

                ${this.renderInvoiceBlock(d)}

                <div style="margin-bottom:14px;">
                    <strong style="font-size:.85rem;">Notas del pedido</strong>
                    <div style="margin-top:6px;">${notesHtml}</div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        ${transitionsHtml || '<span style="font-size:.8rem;color:#9ca3af;">Sin acciones disponibles para este estado.</span>'}
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        ${d.is_redi ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" onclick="LTMS.Dashboard.openIncidentModal(${d.id})" style="border-color:#E80001;color:#E80001;">âš ï¸ Abrir Novedad</button>` : ''}
                        <a href="${d.edit_url}" target="_blank" rel="noopener" class="ltms-btn ltms-btn-outline ltms-btn-sm" aria-label="Ver pedido completo en WordPress">Ver en WordPress â†—</a>
                    </div>
                </div>
            `);
        },

        /**
         * v2.9.222: Renderiza el bloque de facturaciÃ³n electrÃ³nica en el detalle del pedido.
         * Si el vendor tiene credenciales configuradas, muestra el botÃ³n "Generar factura".
         * Si ya se generÃ³, muestra el nÃºmero y enlace.
         * Si no tiene credenciales, muestra un link para configurarlas.
         */
        renderInvoiceBlock(d) {
            // Si no viene invoice_data del backend, asumir no configurado.
            const invoiceData = d.invoice_data || {};
            const hasCreds = invoiceData.has_credentials === true;
            const existing = invoiceData.existing_invoice || null;
            const needsInvoice = invoiceData.buyer_needs_invoice === true;
            const buyerTaxId = invoiceData.buyer_tax_id || '';
            const buyerCompany = invoiceData.buyer_company_name || '';

            let buyerInfo = '';
            if (needsInvoice && buyerTaxId) {
                buyerInfo = `
                    <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;padding:8px 10px;margin-top:8px;font-size:.8rem;color:#1E40AF;">
                        <strong>ðŸ“‹ Comprador solicita factura:</strong><br>
                        ${this.escapeHtml(buyerCompany)} Â· ${this.escapeHtml(buyerTaxId)}
                    </div>
                `;
            } else {
                buyerInfo = `
                    <div style="font-size:.78rem;color:#9ca3af;margin-top:6px;">
                        El comprador no solicitÃ³ factura. Puedes generar una genÃ©rica si lo deseas.
                    </div>
                `;
            }

            let actionHtml = '';
            if (existing) {
                // Ya generada.
                actionHtml = `
                    <div style="display:inline-flex;align-items:center;gap:8px;background:#D1FAE5;color:#065F46;padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:600;">
                        âœ… Factura ${this.escapeHtml(existing.provider.toUpperCase())} #${this.escapeHtml(existing.invoice_number)}
                    </div>
                `;
            } else if (hasCreds) {
                actionHtml = `
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-generate-invoice-btn" data-order-id="${d.id}" style="background:#E80001;border-color:#E80001;">
                        ðŸ“„ Generar factura en ${this.escapeHtml((invoiceData.provider || 'Alegra').charAt(0).toUpperCase() + (invoiceData.provider || 'Alegra').slice(1))}
                    </button>
                `;
            } else {
                actionHtml = `
                    <a href="#" data-action="load-view" data-view="settings" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                        âš™ï¸ Configurar facturaciÃ³n
                    </a>
                `;
            }

            return `
                <div style="background:#FFF9F9;border:1px solid #FFD6D6;border-left:4px solid #E80001;border-radius:8px;padding:12px 14px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                        <strong style="font-size:.85rem;color:#1A1F2E;">ðŸ“„ FacturaciÃ³n electrÃ³nica</strong>
                        ${actionHtml}
                    </div>
                    ${buyerInfo}
                </div>
            `;
        },

        /**
         * AUDIT-REDI-UX-GAPS GAP-11: Abre el modal de crear incidente
         * desde el detalle de un pedido ReDi.
         */
        openIncidentModal(orderId) {
            // Navegar a la vista de Novedades + abrir el modal de creaciÃ³n.
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
         * Cambia el estado de un pedido (acciÃ³n del vendedor desde el modal de detalle).
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
                        self.fetchOrders(); // refresca la tabla detrÃ¡s
                    } else {
                        alert(response.data || 'No se pudo cambiar el estado.');
                    }
                },
                error: () => alert(ltmsDashboard.i18n.error),
            });
        },
        loadProductsView(forceRefresh = false) {
            const self = this;
            // v2.9.99 FIX: mostrar la vista PHP directamente (loadGenericView) en lugar de
            // sobreescribirla con renderProductsView(). La vista PHP tiene pagination,
            // search, gallery upload, ReDi toggle, etc. â€” el JS render era una versiÃ³n
            // simplificada que perdÃ­a todas esas features.
            self.showSection('#ltms-view-products');
            // La vista PHP ya tiene su propia lógica de carga via AJAX inline.
        },
        // v2.9.99 FIX confirmed: renderProductsView removed — the PHP view (view-products.php)
        // is the single source of truth for the product list and modals.
        // AUDIT-PROD-044 FIX: loadNewProductView / loadEditProductView also removed —
        // their existence caused ltms-products.js:207 to prefer them over the modal PHP,
        // making the modal PHP (with variable/visibility/booking fields) dead code.
        // Now ltms-products.js opens ltms-modal-new-product directly (the source of truth).

        loadSettingsView(forceRefresh = false) {
            const self = this;
            // v2.9.99 FIX: mostrar la vista PHP directamente. La vista PHP tiene los 7
            // campos nuevos (vacation_mode, store_logo, schedule, social links), logo
            // upload, copy-referral, checkbox fix â€” el JS render era una versiÃ³n
            // simplificada que sobreescribÃ­a todos esos fixes.
            self.showSection('#ltms-view-settings');
        },
        renderSettingsView(data) {
            const kyc = data.kyc_status || 'pending';
            const kycLabel = {pending:'Pendiente',approved:'Aprobado',rejected:'Rechazado'}[kyc] || kyc;
            const kycColor = {pending:'#f59e0b',approved:'#10b981',rejected:'#ef4444'}[kyc] || '#888';
            const store = data.store || {};
            const kycUrl = ltmsDashboard.kyc_url || '/verificacion-identidad/';
            const kycBlock = kyc !== 'approved'
                ? `<p style="margin:10px 0;color:#666;">Para solicitar retiros, debes completar la verificaciÃ³n de identidad.</p><a href="${kycUrl}" class="ltms-btn ltms-btn-outline">Completar KYC</a>`
                : '<p style="color:#10b981;">âœ“ Identidad verificada.</p>';
            $('#ltms-view-settings').html(`
                <h3 style="margin-bottom:20px;">ConfiguraciÃ³n de Mi Cuenta</h3>
                <div class="ltms-card" style="margin-bottom:20px;padding:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong>VerificaciÃ³n de Identidad (KYC)</strong>
                        <span style="color:${kycColor};font-weight:600;">${kycLabel}</span>
                    </div>${kycBlock}
                </div>
                <div class="ltms-card" style="padding:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Datos de la Tienda</h4>
                    <div class="ltms-form-group"><label>Nombre de la Tienda</label><input type="text" class="ltms-form-control" name="store_name" value="${this.escapeHtml(store.name||'')}" placeholder="Mi Tienda"></div>
                    <div class="ltms-form-group"><label>TelÃ©fono de Contacto</label><input type="text" class="ltms-form-control" name="store_phone" value="${this.escapeHtml(store.phone||'')}" placeholder="+57 300 000 0000"></div>
                    <div class="ltms-form-group"><label>DescripciÃ³n</label><textarea class="ltms-form-control" name="store_description" rows="3">${this.escapeHtml(store.description||'')}</textarea></div>
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:12px;">
                        <p style="font-size:0.78rem;font-weight:600;color:#374151;margin:0 0 12px;text-transform:uppercase;letter-spacing:.5px;">ðŸ¦ Cuenta Bancaria para Retiros</p>
                        <p style="font-size:0.75rem;color:#6b7280;margin:0 0 12px;">Esta cuenta se usarÃ¡ automÃ¡ticamente al solicitar un retiro.</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div class="ltms-form-group" style="margin:0;"><label>Banco</label><input type="text" class="ltms-form-control" name="ltms_bank_name" value="${this.escapeHtml(store.bank_name||'')}" placeholder="Ej: Bancolombia"></div>
                            <div class="ltms-form-group" style="margin:0;"><label>Tipo de Cuenta</label><select class="ltms-form-control" name="ltms_bank_account_type"><option value="ahorros" ${(store.bank_account_type||'ahorros')==='ahorros'?'selected':''}>Ahorros</option><option value="corriente" ${(store.bank_account_type||'')==='corriente'?'selected':''}>Corriente</option><option value="nequi" ${(store.bank_account_type||'')==='nequi'?'selected':''}>Nequi</option><option value="daviplata" ${(store.bank_account_type||'')==='daviplata'?'selected':''}>Daviplata</option></select></div>
                        </div>
                        <div class="ltms-form-group" style="margin-bottom:10px;"><label>NÃºmero de Cuenta</label><input type="text" class="ltms-form-control" name="ltms_bank_account_number" value="${this.escapeHtml(store.bank_account_number||'')}" placeholder="Ej: 69812345678"></div>
                        <div class="ltms-form-group" style="margin:0;"><label>Nombre del Titular</label><input type="text" class="ltms-form-control" name="ltms_bank_account_holder" value="${this.escapeHtml(store.bank_account_holder||'')}" placeholder="Nombre como aparece en el banco"></div>
                    </div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-settings-btn">ðŸ’¾ Guardar Cambios</button>
                    <span class="ltms-settings-msg" style="margin-left:10px;display:none;"></span>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Perfil PÃºblico de la Tienda</h4>
                    <div class="ltms-form-group"><label>Nombre PÃºblico</label><input type="text" class="ltms-form-control" name="ltms_store_name" value="${this.escapeHtml(store.store_name||store.name||'')}" placeholder="Nombre visible al comprador"></div>
                    <div class="ltms-form-group"><label>DirecciÃ³n</label><input type="text" class="ltms-form-control" name="ltms_store_address" value="${this.escapeHtml(store.store_address||'')}" placeholder="Calle, carrera, barrio"></div>
                    <div class="ltms-form-group"><label>Ciudad</label><input type="text" class="ltms-form-control" name="ltms_store_city" value="${this.escapeHtml(store.store_city||'')}" placeholder="BogotÃ¡, MedellÃ­n..."></div>
                    <div class="ltms-form-group"><label>TelÃ©fono PÃºblico</label><input type="text" class="ltms-form-control" name="ltms_store_phone" value="${this.escapeHtml(store.store_phone||store.phone||'')}" placeholder="+57 300 000 0000"></div>
                    <div class="ltms-form-group"><label>Horario de AtenciÃ³n</label><textarea class="ltms-form-control" name="ltms_store_schedule" rows="2" placeholder="Lun-Vie 8am-6pm">${this.escapeHtml(store.store_schedule||'')}</textarea></div>
                    <div class="ltms-form-group"><label>CategorÃ­as (separadas por coma)</label><input type="text" class="ltms-form-control" name="ltms_store_categories" value="${this.escapeHtml(store.store_categories||'')}" placeholder="Ropa, Calzado, Accesorios"></div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-profile-btn">ðŸ’¾ Guardar Perfil</button>
                    <span class="ltms-profile-msg" style="margin-left:10px;display:none;"></span>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:4px;">ðŸ–¼ï¸ Banner de la Tienda</h4>
                    <p style="font-size:.8rem;color:#6b7280;margin:0 0 12px;">
                        Esta imagen aparece como fondo del encabezado de tu pÃ¡gina pÃºblica en
                        <strong>lo-tengo.com.co/vendedor/tu-tienda</strong>.
                    </p>
                    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.8rem;color:#0369a1;line-height:1.6;">
                        <strong>ðŸ“ TamaÃ±o recomendado:</strong> 1440 Ã— 320 pÃ­xeles (relaciÃ³n 4.5:1)<br>
                        <strong>ðŸ“± En mÃ³vil</strong> se recorta al centro â€” pon lo importante en el medio.<br>
                        <strong>âš–ï¸ Peso mÃ¡ximo:</strong> 3 MB Â· Formatos: JPG, PNG, WebP<br>
                        <strong>ðŸ’¡ Consejo:</strong> fondos sÃ³lidos, degradados o fotos con poco texto funcionan mejor.
                    </div>
                    <div id="ltms-banner-current-wrap" style="display:none;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <p style="font-size:.78rem;font-weight:600;color:#374151;margin:0;">ðŸ–¼ï¸ Banner actual</p>
                            <button type="button" class="ltms-delete-banner-btn"
                                    style="display:inline-flex;align-items:center;gap:5px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:5px 12px;font-size:.78rem;font-weight:600;cursor:pointer;">
                                ðŸ—‘ï¸ Eliminar
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
                            ðŸ“‚ Seleccionar imagen
                            <input type="file" id="ltms-banner-file" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        </label>
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-upload-banner-btn">ðŸ–¼ï¸ Subir Banner</button>
                        <span class="ltms-banner-msg" style="font-size:.85rem;display:none;"></span>
                    </div>
                    <p id="ltms-banner-filename" style="font-size:.78rem;color:#6b7280;margin:6px 0 0;display:none;"></p>
                </div>
                <div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <h4 style="margin-bottom:15px;">Zona de Despacho</h4>
                    <div class="ltms-form-group"><label>Ciudades de cobertura (separadas por coma)</label><input type="text" class="ltms-form-control" id="ltms-dz-cities" value="${this.escapeHtml((store.delivery_zone&&store.delivery_zone.cities||[]).join(', '))}" placeholder="BogotÃ¡, Soacha"></div>
                    <div class="ltms-form-group"><label>Radio mÃ¡ximo (km)</label><input type="number" class="ltms-form-control" id="ltms-dz-radius" min="0" value="${store.delivery_zone&&store.delivery_zone.radius_km||0}"></div>
                    <div class="ltms-form-group"><label>EnvÃ­o gratis desde (COP)</label><input type="number" class="ltms-form-control" id="ltms-dz-free" min="0" value="${store.delivery_zone&&store.delivery_zone.free_from||0}"></div>
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-save-zone-btn">ðŸ’¾ Guardar Zona</button>
                    <span class="ltms-zone-msg" style="margin-left:10px;display:none;"></span>
                </div>`);
            // Analytics card (appended separately to avoid nested backtick issues)
            if (data.store.vendor_ga4_enabled || data.store.vendor_pixel_enabled) {
                let analyticsHtml = '<div class="ltms-card" style="padding:20px;margin-top:20px;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);">';
                analyticsHtml += '<h4 style="margin-bottom:8px;">ðŸ“Š Analytics & Tracking de Mi Tienda</h4>';
                analyticsHtml += '<p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">Configura tu propio pixel para medir el trÃ¡fico hacia tus productos. Solo se activan en las pÃ¡ginas de tus productos.</p>';
                if (data.store.vendor_ga4_enabled) {
                    analyticsHtml += '<div class="ltms-form-group"><label>Google Analytics 4 â€” Measurement ID</label>';
                    analyticsHtml += '<input type="text" class="ltms-form-control" id="ltms-vendor-ga4" value="' + this.escapeHtml(data.store.vendor_ga4_id||'') + '" placeholder="G-XXXXXXXXXX">';
                    analyticsHtml += '<small style="color:#9ca3af;">EncuÃ©ntralo en Google Analytics â†’ Admin â†’ Flujos de datos.</small></div>';
                }
                if (data.store.vendor_pixel_enabled) {
                    analyticsHtml += '<div class="ltms-form-group"><label>Meta Pixel ID (Facebook / Instagram)</label>';
                    analyticsHtml += '<input type="text" class="ltms-form-control" id="ltms-vendor-pixel" value="' + this.escapeHtml(data.store.vendor_pixel_id||'') + '" placeholder="123456789012345">';
                    analyticsHtml += '<small style="color:#9ca3af;">EncuÃ©ntralo en Meta Business Suite â†’ Fuentes de datos â†’ PÃ­xeles.</small></div>';
                }
                analyticsHtml += '<button type="button" class="ltms-btn ltms-btn-primary ltms-save-analytics-btn">ðŸ’¾ Guardar Analytics</button>';
                analyticsHtml += '<span class="ltms-analytics-msg" style="margin-left:10px;display:none;"></span></div>';
                document.getElementById('ltms-view-settings') && (document.getElementById('ltms-view-settings').insertAdjacentHTML('beforeend', analyticsHtml));
            }

            // Inicializar banner actual si existe
            const bannerUrl = store.store_banner_url || '';
            if (bannerUrl) {
                $('#ltms-banner-current').attr('src', bannerUrl);
                $('#ltms-banner-current-wrap').show();
            }

            // Handler: guardar configuraciÃ³n bÃ¡sica (products-ajax)
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
                    success(r) { btn.prop('disabled',false).text('ðŸ’¾ Guardar Cambios');
                        const m=$('.ltms-settings-msg');
                        m.text(r.success?'âœ“ Guardado':'Error al guardar').css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000); },
                    error(){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Cambios'); }
                });
            });

            // Handler: guardar perfil pÃºblico (ltms_save_vendor_profile â€” Vendor_Settings_Saver)
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
                    success(r){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Perfil');
                        const m=$('.ltms-profile-msg');
                        m.text(r.success?'âœ“ Perfil guardado':'Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Perfil'); }
                });
            });

            // Handler: preview al seleccionar archivo
            $(document).off('change','#ltms-banner-file').on('change','#ltms-banner-file', function() {
                const file = this.files[0];
                if (!file) return;
                const maxMB = 3;
                if (file.size > maxMB * 1024 * 1024) {
                    const m = $('.ltms-banner-msg');
                    m.text('La imagen pesa ' + (file.size/1024/1024).toFixed(1) + ' MB. El mÃ¡ximo es ' + maxMB + ' MB.').css('color','#ef4444').show();
                    this.value = '';
                    $('#ltms-banner-preview-wrap').hide();
                    $('#ltms-banner-filename').hide();
                    return;
                }
                $('.ltms-banner-msg').hide();
                $('#ltms-banner-filename').text('ðŸ“„ ' + file.name + ' â€” ' + (file.size/1024).toFixed(0) + ' KB').show();
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#ltms-banner-preview').attr('src', e.target.result);
                    $('#ltms-banner-preview-wrap').show();
                };
                reader.readAsDataURL(file);
            });

            // Handler: subir banner (ltms_upload_store_banner â€” Vendor_Settings_Saver)
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
                    success(r){ btn.prop('disabled',false).text('ðŸ–¼ï¸ Subir Banner');
                        m.text(r.success?'âœ… Banner actualizado correctamente':'âŒ Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        if (r.success && r.data && r.data.url) {
                            $('#ltms-banner-current').attr('src', r.data.url);
                            $('#ltms-banner-current-wrap').show();
                            $('#ltms-banner-file').val('');
                            $('#ltms-banner-filename').hide();
                            $('#ltms-banner-preview-wrap').hide();
                        }
                        setTimeout(()=>m.hide(),5000);
                    },
                    error(){ btn.prop('disabled',false).text('ðŸ–¼ï¸ Subir Banner');
                        m.text('âŒ Error de red, intente de nuevo.').css('color','#ef4444').show();
                    }
                });
            });

            // Handler: eliminar banner (ltms_delete_store_banner â€” Vendor_Settings_Saver)
            $(document).off('click','.ltms-delete-banner-btn').on('click','.ltms-delete-banner-btn', function() {
                if (!confirm('Â¿Eliminar el banner actual? Esta acciÃ³n no se puede deshacer.')) return;
                const btn=$(this); btn.prop('disabled',true).text('Eliminando...');
                const m=$('.ltms-banner-msg');
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_delete_store_banner', nonce:ltmsDashboard.nonce },
                    success(r){ btn.prop('disabled',false);
                        if (r.success) {
                            $('#ltms-banner-current-wrap').hide();
                            $('#ltms-banner-current').attr('src','');
                            m.text('âœ… Banner eliminado correctamente').css('color','#10b981').show();
                        } else {
                            btn.text('ðŸ—‘ï¸ Eliminar banner');
                            m.text('âŒ Error: '+(r.data||'intente de nuevo')).css('color','#ef4444').show();
                        }
                        setTimeout(()=>m.hide(),5000);
                    },
                    error(){ btn.prop('disabled',false).text('ðŸ—‘ï¸ Eliminar banner');
                        m.text('âŒ Error de red.').css('color','#ef4444').show();
                    }
                });
            });

            // Handler: guardar zona de despacho (ltms_save_delivery_zone â€” Vendor_Settings_Saver)
            $(document).off('click','.ltms-save-zone-btn').on('click','.ltms-save-zone-btn', function() {
                const btn=$(this); btn.prop('disabled',true).text('Guardando...');
                const cities=$('#ltms-dz-cities').val().split(',').map(s=>s.trim()).filter(Boolean);
                $.ajax({ url:ltmsDashboard.ajax_url, method:'POST',
                    data:{ action:'ltms_save_delivery_zone', nonce:ltmsDashboard.nonce,
                        cities:cities, radius_km:$('#ltms-dz-radius').val(), free_from:$('#ltms-dz-free').val() },
                    success(r){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Zona');
                        const m=$('.ltms-zone-msg');
                        m.text(r.success?'âœ“ Zona guardada':'Error: '+(r.data||'intente de nuevo')).css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Zona'); }
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
                    success(r){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Analytics');
                        const m=$('.ltms-analytics-msg');
                        m.text(r.success?'âœ… Guardado':'âŒ Error').css('color',r.success?'#10b981':'#ef4444').show();
                        setTimeout(()=>m.hide(),3000);
                    },
                    error(){ btn.prop('disabled',false).text('ðŸ’¾ Guardar Analytics'); }
                });
            });
        },
        /**
         * Carga una vista genÃ©rica como fallback.
         *
         * @param {string} view Nombre de la vista.
         */
        // â”€â”€ Vista: Seguros â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        loadInsuranceView(forceRefresh = false) {
            const self = this;
            // v2.9.99 FIX: mostrar la vista PHP directamente. La vista PHP tiene KPIs,
            // coverage info card, filtros, CSV export, empty state SVG â€” el JS render
            // era una versiÃ³n simplificada que perdÃ­a todas esas features.
            self.showSection('#ltms-view-insurance');
        },

        renderInsuranceView(data) {
            const policies = data.policies || [];
            let rows = '';
            if (policies.length === 0) {
                rows = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Sin pÃ³lizas registradas.</td></tr>';
            } else {
                policies.forEach(p => {
                    const statusColor = p.status === 'active' ? '#10b981' : p.status === 'claimed' ? '#f59e0b' : '#6b7280';
                    rows += `<tr>
                        <td>#${p.order_id}</td>
                        <td>${this.escapeHtml(p.insurance_type || '')}</td>
                        <td>${this.escapeHtml(p.policy_number || p.policy_id || '')}</td>
                        <td>${this.formatMoney(parseFloat(p.premium_amount || 0))}</td>
                        <td><span style="color:${statusColor};font-weight:600;">${p.status}</span></td>
                        <td>${p.certificate_url ? `<a href="${this.escapeHtml(p.certificate_url)}" target="_blank">ðŸ“„ Ver</a>` : 'â€”'}</td>
                    </tr>`;
                });
            }
            this.showSection('#ltms-view-insurance');
            $('#ltms-view-insurance').html(`
                <div class="ltms-section-header"><h2>ðŸ›¡ï¸ Mis Seguros</h2></div>
                <div class="ltms-card" style="overflow-x:auto;">
                    <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                        <thead><tr>
                            <th>Pedido</th><th>Tipo</th><th>PÃ³liza</th>
                            <th>Prima</th><th>Estado</th><th>Certificado</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`);
        },

        // â”€â”€ Vista: ReDi â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        loadRediView(forceRefresh = false) {
            const self = this;
            // v2.9.99 FIX: mostrar la vista PHP directamente. La vista PHP tiene
            // wc_price correcto, redi_rate Ã—100, toggleRediRow DOM swap â€” el JS render
            // era una versiÃ³n simplificada que sobreescribÃ­a todos esos fixes.
            self.showSection('#ltms-view-redi');
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
                <div class="ltms-section-header"><h2>ðŸ” ReDi â€” Productos en Reventa</h2></div>
                <div class="ltms-card" style="margin-bottom:20px;">
                    <h4 style="margin-bottom:12px;">Mis Acuerdos Activos</h4>
                    <div style="overflow-x:auto;">
                        <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                            <thead><tr><th>Producto</th><th>ComisiÃ³n</th><th>Estado</th><th>AcciÃ³n</th></tr></thead>
                            <tbody>${agreementRows}</tbody>
                        </table>
                    </div>
                </div>
                <div class="ltms-card">
                    <h4 style="margin-bottom:12px;">Productos Disponibles para Adoptar</h4>
                    <div style="overflow-x:auto;">
                        <table class="ltms-table" style="width:100%;border-collapse:collapse;">
                            <thead><tr><th>Producto</th><th>ComisiÃ³n</th><th>AcciÃ³n</th></tr></thead>
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
                if (!confirm('Â¿Confirmar revocaciÃ³n del acuerdo?')) return;
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

        // â”€â”€ Vista: Descargas Seguras â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        loadDownloadsView(forceRefresh = false) {
            const self = this;
            if (!forceRefresh && this.dataCache['downloads']) {
                this.renderDownloadsView(this.dataCache['downloads']);
                return;
            }
            // La vista de descargas la renderiza PHP directamente; solo mostramos
            // la secciÃ³n estÃ¡tica y un botÃ³n para generar token de descarga si hay productos digitales.
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
                rows = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#888;">Sin productos digitales vendidos aÃºn.</td></tr>';
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
                                style="font-size:12px;">ðŸ”‘ Generar Token</button>
                        </td>
                    </tr>`;
                });
            }
            this.showSection('#ltms-view-downloads');
            $('#ltms-view-downloads').html(`
                <div class="ltms-section-header"><h2>ðŸ“¦ Descargas Seguras</h2></div>
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
                        btn.prop('disabled', false).text('ðŸ”‘ Generar Token');
                        if (r.success && r.data.token_url) {
                            $('#ltms-token-result')
                                .html(`âœ… Token generado: <a href="${r.data.token_url}" target="_blank">${r.data.token_url}</a>`)
                                .show();
                        } else {
                            alert(r.data || 'Error al generar token.');
                        }
                    },
                    error() { btn.prop('disabled', false).text('ðŸ”‘ Generar Token'); }
                });
            });
        },

        loadGenericView(view) {
            this.showSection('#ltms-view-' + view);
        },

        // â”€â”€ Formatters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        /**
         * Formatea un nÃºmero como moneda local.
         *
         * @param {number}  amount    Monto.
         * @param {boolean} compact   Si usar notaciÃ³n compacta.
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

        // v2.9.95 P3: Localized date formatter
        formatDate(dateStr, includeTime = false) {
            if (!dateStr) return 'â€”';
            try {
                var d = new Date(dateStr);
                var locale = ltmsDashboard.country === 'MX' ? 'es-MX' : 'es-CO';
                var opts = { year: 'numeric', month: 'short', day: 'numeric' };
                if (includeTime) { opts.hour = '2-digit'; opts.minute = '2-digit'; }
                return d.toLocaleDateString(locale, opts);
            } catch(e) { return dateStr; }
        },

        // v2.9.95 P3: Relative time formatter
        formatRelative(dateStr) {
            if (!dateStr) return 'â€”';
            try {
                var d = new Date(dateStr);
                var now = new Date();
                var diff = (now - d) / 1000;
                if (diff < 60) return 'Hace un momento';
                if (diff < 3600) return 'Hace ' + Math.floor(diff / 60) + ' min';
                if (diff < 86400) return 'Hace ' + Math.floor(diff / 3600) + ' h';
                if (diff < 604800) return 'Hace ' + Math.floor(diff / 86400) + ' dÃ­as';
                return this.formatDate(dateStr);
            } catch(e) { return dateStr; }
        },

        /**
         * Obtiene la clase CSS para el estado de un pedido.
         *
         * @param {string} status Estado del pedido.
         * @returns {string}
         */
        /**
         * Obtiene la clase CSS para el estado de un pedido.
         * P-01: incluye ready-for-pickup con su propio badge y etiquetas en espaÃ±ol.
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
         * Devuelve la etiqueta legible en espaÃ±ol para un estado de pedido.
         * P-01: evita mostrar el slug crudo (ej. "ready-for-pickup") en la tabla.
         *
         * @param {string} status Estado WC.
         * @returns {string}
         */
        getOrderStatusLabel(status) {
            const map = {
                pending:            'Pendiente',
                processing:         'Procesando',
                'ready-for-pickup': 'ðŸ“¦ Listo para Recoger',
                completed:          'Completado',
                cancelled:          'Cancelado',
                refunded:           'Reembolsado',
                'on-hold':          'En espera',
            };
            return map[status] || status;
        },

        /**
         * Obtiene la clase CSS para el tipo de transacciÃ³n.
         *
         * @param {string} type Tipo de transacciÃ³n.
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

        // â”€â”€ v2.9.82 P2: Dark Mode Toggle â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        initDarkMode() {
            if (localStorage.getItem('ltms-dark-mode') === 'yes') {
                document.body.classList.add('ltms-dark-mode');
                $('#ltms-dark-mode-toggle').text('â˜€ï¸');
            }
            $(document).on('click', '#ltms-dark-mode-toggle', function() {
                var isDark = document.body.classList.toggle('ltms-dark-mode');
                localStorage.setItem('ltms-dark-mode', isDark ? 'yes' : 'no');
                $(this).text(isDark ? 'â˜€ï¸' : 'ðŸŒ™');
            });
        },

        // â”€â”€ v2.9.82 P2: CSV Export para Wallet â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        // â”€â”€ v2.9.82 P2: Breadcrumbs dinÃ¡micos â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        initBreadcrumbs() {
            const self = this;
            const labels = {
                home: 'Inicio', orders: 'Pedidos', products: 'Productos',
                wallet: 'Billetera', settings: 'ConfiguraciÃ³n', envios: 'EnvÃ­os',
                'shipping-statement': 'Fletes', redi: 'ReDi', incidents: 'Novedades',
                bookings: 'Reservas', marketing: 'Marketing', security: 'Seguridad',
                donations: 'Donaciones', posgold: 'PosGold', kitchen: 'Cocina',
                'ordenes-compra': 'Ã“rdenes de Compra', analytics: 'Analytics'
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

        // â”€â”€ v2.9.84 P1: Global Search en topbar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        initGlobalSearch() {
            let searchTimer = null;
            $(document).on('input', '#ltms-topbar-search-input', function() {
                clearTimeout(searchTimer);
                const query = $(this).val().trim();
                if (query.length < 2) return;
                searchTimer = setTimeout(function() {
                    if ($('#ltms-order-search').length) {
                        $('#ltms-order-search').val(query).trigger('input');
                    }
                    if ($('#ltms-product-search').length) {
                        $('#ltms-product-search').val(query).trigger('input');
                    }
                    if (!$('#ltms-order-search').length && !$('#ltms-product-search').length) {
                        if (typeof LTMS !== 'undefined' && LTMS.Dashboard) {
                            LTMS.Dashboard.loadView('orders');
                            setTimeout(function() {
                                $('#ltms-order-search').val(query).trigger('input');
                            }, 500);
                        }
                    }
                }, 300);
            });
        },

        // â”€â”€ v2.9.91 P3: Keyboard Shortcuts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        initKeyboardShortcuts() {
            const self = this;
            const shortcuts = {
                'g+h': 'home', 'g+o': 'orders', 'g+p': 'products',
                'g+w': 'wallet', 'g+s': 'settings', 'g+k': 'kyc',
                'g+r': 'redi', 'g+e': 'envios', 'g+m': 'marketing',
                'g+d': 'donations', 'g+u': 'security', 'g+f': 'posgold'
            };
            let keyBuffer = '';
            let keyTimer = null;

            $(document).on('keydown', function(e) {
                // Ignorar si estÃ¡ escribiendo en un input/textarea
                if ($(e.target).is('input, textarea, select, [contenteditable]')) return;
                if (e.ctrlKey || e.metaKey || e.altKey) return;

                const key = e.key.toLowerCase();
                keyBuffer += key;
                clearTimeout(keyTimer);
                keyTimer = setTimeout(function() { keyBuffer = ''; }, 800);

                // Verificar si el buffer coincide con un shortcut
                for (const [combo, view] of Object.entries(shortcuts)) {
                    if (keyBuffer === combo) {
                        e.preventDefault();
                        self.loadView(view);
                        keyBuffer = '';
                        return;
                    }
                }

                // 'd' toggle dark mode
                if (keyBuffer === 'd' && keyBuffer.length === 1) {
                    // Solo si no hay 'g' antes
                    return;
                }

                // '/' focus search
                if (key === '/' ) {
                    e.preventDefault();
                    $('#ltms-topbar-search-input').focus();
                    keyBuffer = '';
                }

                // '?' show keyboard shortcuts help
                if (key === '?') {
                    e.preventDefault();
                    $('#ltms-shortcuts-modal').show();
                    keyBuffer = '';
                }

                // Escape cierra modales
                if (key === 'escape') {
                    $('.ltms-modal').hide();
                    $('.ltms-notifications-panel').removeClass('open');
                    $('#ltms-notif-bell').attr('aria-expanded', 'false');
                }
            });

            // v2.9.94 P3: Close shortcuts modal
            $(document).on('click', '[data-action="close-shortcuts"]', function() {
                $('#ltms-shortcuts-modal').hide();
            });
        },
    };

    // â”€â”€ Inicializar cuando el DOM estÃ© listo â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $(document).ready(function () {
        if (typeof ltmsDashboard !== 'undefined' && $('#ltms-dashboard-container').length) {
            LTMS.Dashboard.init();
        }
    });

})(jQuery);
