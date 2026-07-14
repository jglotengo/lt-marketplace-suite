/**
 * LT Marketplace Suite - Admin JavaScript
 * Backend WordPress Dashboard Scripts
 * Version: 1.5.0
 */

/* global ltmsAdmin, jQuery, Chart */

(function ($) {
    'use strict';

    // ── Namespace principal ──────────────────────────────────────
    window.LTMS = window.LTMS || {};
    const LTMS = window.LTMS;

    /**
     * LTMS.Admin - Módulo principal del backend
     */
    LTMS.Admin = {

        /**
         * Inicializa todos los módulos del admin.
         */
        init() {
            this.initCharts();
            this.initAjaxActions();
            this.initFormValidation();
            this.initSettingsToggle();
            this.initDataTables();
            this.initFlashMessages();
            this.initConfirmDialogs();
        },

        /**
         * Inicializa los gráficos del dashboard con Chart.js.
         */
        initCharts() {
            // Gráfico de ventas (si existe el canvas)
            const salesCtx = document.getElementById('ltms-sales-chart');
            if (salesCtx && typeof Chart !== 'undefined') {
                this.loadChartData('sales', salesCtx);
            }

            const commCtx = document.getElementById('ltms-commissions-chart');
            if (commCtx && typeof Chart !== 'undefined') {
                this.loadChartData('commissions', commCtx);
            }
        },

        /**
         * Carga datos de un gráfico vía AJAX.
         *
         * @param {string} type   Tipo de gráfico.
         * @param {HTMLElement} ctx Canvas element.
         */
        loadChartData(type, ctx) {
            $.ajax({
                url: ltmsAdmin.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_chart_data',
                    type: type,
                    nonce: ltmsAdmin.nonce,
                },
                success(response) {
                    if (!response.success || !response.data) return;

                    new Chart(ctx, {
                        type: response.data.chart_type || 'bar',
                        data: response.data.chart_data,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: type !== 'sales' },
                                tooltip: {
                                    callbacks: {
                                        label(context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            label += new Intl.NumberFormat('es-CO', {
                                                style: 'currency',
                                                currency: ltmsAdmin.currency,
                                                minimumFractionDigits: 0,
                                            }).format(context.parsed.y);
                                            return label;
                                        },
                                    },
                                },
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback(value) {
                                            return new Intl.NumberFormat('es-CO', {
                                                style: 'currency',
                                                currency: ltmsAdmin.currency,
                                                minimumFractionDigits: 0,
                                                notation: 'compact',
                                            }).format(value);
                                        },
                                    },
                                },
                            },
                        },
                    });
                },
                error() {
                    console.warn('[LTMS Admin] Error cargando datos del gráfico:', type);
                },
            });
        },

        /**
         * Inicializa acciones AJAX (aprobar retiros, congelar billeteras, etc.).
         */
        initAjaxActions() {
            const self = this;

            // Aprobar solicitud de retiro
            $(document).on('click', '.ltms-approve-payout', function (e) {
                e.preventDefault();
                const payoutId = $(this).data('payout-id');
                // A-7 FIX: texto correcto para aprobación (no reutilizar confirm_delete)
                if (!confirm(ltmsAdmin.i18n.confirm_approve_payout || '¿Aprobar este retiro? El pago se procesará de inmediato.')) return;

                const $btn_payout = $(this);
                self.ajaxAction('ltms_approve_payout', { payout_id: payoutId }, function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $btn_payout.closest('tr').fadeOut(400, function () { $(this).remove(); });
                    } else {
                        // A-7 FIX: response.data puede ser objeto
                        const errMsg = typeof response.data === 'string' ? response.data : (response.data?.message || ltmsAdmin.i18n.error || 'Error');
                        self.showNotice('error', errMsg);
                    }
                });
            });

            // Rechazar solicitud de retiro
            $(document).on('click', '.ltms-reject-payout', function (e) {
                e.preventDefault();
                const payoutId = $(this).data('payout-id');
                const reason = prompt('Motivo del rechazo (requerido):');
                if (!reason || !reason.trim()) return;

                const $btn_reject = $(this);
                self.ajaxAction('ltms_reject_payout', { payout_id: payoutId, reason: reason }, function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $btn_reject.closest('tr').fadeOut(400, function () { $(this).remove(); });
                    } else {
                        self.showNotice('error', response.data);
                    }
                });
            });

            // Congelar billetera
            $(document).on('click', '.ltms-freeze-wallet', function (e) {
                e.preventDefault();
                const vendorId = $(this).data('vendor-id');
                const reason = prompt('Motivo del congelamiento (cumplimiento):');
                if (!reason || !reason.trim()) return;

                self.ajaxAction('ltms_freeze_wallet', { vendor_id: vendorId, reason: reason }, (response) => {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        location.reload();
                    } else {
                        self.showNotice('error', response.data);
                    }
                });
            });

            // Verificar KYC
            $(document).on('click', '.ltms-approve-kyc', function (e) {
                e.preventDefault();
                const kycId = $(this).data('kyc-id');
                const $btn_kyc = $(this);
                self.ajaxAction('ltms_approve_kyc', { kyc_id: kycId }, function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $btn_kyc.closest('tr').find('.ltms-badge').removeClass().addClass('ltms-badge ltms-badge-success').text('Aprobado');
                        $btn_kyc.closest('.ltms-actions').hide();
                    } else {
                        self.showNotice('error', response.data);
                    }
                });
            });

            // Exportar solicitudes de retiro a CSV (A-8a)
            $(document).on('click', '.ltms-export-payouts', function (e) {
                e.preventDefault();
                const $btn = $(this);
                $btn.prop('disabled', true).text('Exportando…');

                $.ajax({
                    url: ltmsAdmin.ajax_url,
                    method: 'POST',
                    data: { action: 'ltms_export_payouts', nonce: ltmsAdmin.nonce },
                    xhrFields: { responseType: 'blob' },
                    success(blob) {
                        const url = URL.createObjectURL(blob);
                        const a   = document.createElement('a');
                        a.href  = url;
                        a.download = 'retiros-ltms-' + new Date().toISOString().slice(0,10) + '.csv';
                        a.click();
                        URL.revokeObjectURL(url);
                    },
                    error() {
                        LTMS.Admin.showNotice('error', 'No se pudo exportar el CSV. Intenta de nuevo.');
                    },
                    complete() {
                        $btn.prop('disabled', false).text('Exportar CSV');
                    },
                });
            });

            // Rechazar KYC desde la vista KYC (A-8b)
            $(document).on('click', '.ltms-reject-kyc', function (e) {
                e.preventDefault();
                const kycId = $(this).data('kyc-id');
                const reason = prompt('Motivo del rechazo (requerido):');
                if (!reason || !reason.trim()) return;

                LTMS.Admin.ajaxAction('ltms_reject_kyc', { kyc_id: kycId, reason }, (response) => {
                    if (response.success) {
                        LTMS.Admin.showNotice('success', response.data?.message || 'KYC rechazado.');
                        $(this).closest('tr').find('.ltms-badge')
                            .removeClass().addClass('ltms-badge ltms-badge-danger').text('Rechazado');
                        $(this).closest('.ltms-actions, td').find('.ltms-approve-kyc, .ltms-reject-kyc').hide();
                    } else {
                        const msg = typeof response.data === 'string' ? response.data : (response.data?.message || 'Error');
                        LTMS.Admin.showNotice('error', msg);
                    }
                });
            });

            // Aprobación rápida de KYC desde lista de vendedores (A-8c)
            $(document).on('click', '.ltms-quick-approve-kyc', function (e) {
                e.preventDefault();
                const vendorId = $(this).data('vendor-id');
                if (!confirm('¿Aprobar el KYC de este vendedor y activar su cuenta?')) return;
                const $btn = $(this);
                $btn.prop('disabled', true);

                LTMS.Admin.ajaxAction('ltms_quick_approve_kyc', { vendor_id: vendorId }, (response) => {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        LTMS.Admin.showNotice('success', response.data?.message || 'KYC aprobado.');
                        $btn.closest('tr').find('.ltms-badge-warning')
                            .removeClass('ltms-badge-warning').addClass('ltms-badge-success').text('Approved');
                        $btn.hide();
                    } else {
                        const msg = typeof response.data === 'string' ? response.data : (response.data?.message || 'Error');
                        LTMS.Admin.showNotice('error', msg);
                    }
                });
            });

            // Verificar RNT (turismo) (A-8d)
            $(document).on('click', '.ltms-approve-rnt', function (e) {
                e.preventDefault();
                const id = $(this).data('compliance-id') || $(this).data('id');
                LTMS.Admin.ajaxAction('ltms_admin_verify_rnt', { id }, (response) => {
                    if (response.success) {
                        LTMS.Admin.showNotice('success', response.data?.message || 'RNT verificado.');
                        location.reload();
                    } else {
                        LTMS.Admin.showNotice('error', response.data?.message || 'Error al verificar RNT.');
                    }
                });
            });

            // Probar conexión con API externa
            $(document).on('click', '.ltms-test-api-connection', function (e) {
                e.preventDefault();
                const $btn = $(this);
                const provider = $btn.data('provider');
                self.testApiConnection(provider, $btn);
            });

            // Descongelar billetera de vendedor
            $(document).on('click', '.ltms-unfreeze-wallet', function (e) {
                e.preventDefault();
                const vendorId = $(this).data('vendor-id');
                self.unfreezeWallet(vendorId, $(this));
            });

            // v2.9.131: Event delegation for data-action attributes
            // (replaces inline onclick handlers removed for CSP compliance)
            $(document).on('click', '[data-action]', function (e) {
                const $el = $(this);
                const action = $el.data('action');

                // data-confirm is handled by initConfirmDialogs() — if it returned false,
                // this handler won't fire because stopImmediatePropagation was called.
                // But just in case, check again:
                const confirmMsg = $el.data('confirm');
                if (confirmMsg && !window.confirm(confirmMsg)) {
                    e.preventDefault();
                    return;
                }

                switch (action) {
                    case 'ltms-test-api':
                        e.preventDefault();
                        self.testApiConnection($el.data('provider'), $el);
                        break;

                    case 'ltms_update_order_status':
                        e.preventDefault();
                        self.ajaxAction('ltms_update_order_status', {
                            order_id: $el.data('order-id'),
                            status: $el.data('status'),
                        }, function (r) {
                            if (r.success) {
                                location.reload();
                            } else {
                                self.showNotice('error', r.data || 'Error');
                            }
                        });
                        break;

                    case 'ltms_unfreeze_wallet':
                        e.preventDefault();
                        self.unfreezeWallet($el.data('vendor-id'), $el);
                        break;

                    case 'ltms-toggle-banner':
                        e.preventDefault();
                        if (typeof ltmsToggleBanner === 'function') {
                            ltmsToggleBanner($el.data('banner-id'), $el[0]);
                        }
                        break;

                    case 'ltms-delete-banner':
                        e.preventDefault();
                        if (typeof ltmsDeleteBanner === 'function') {
                            ltmsDeleteBanner($el.data('banner-id'), $el[0]);
                        }
                        break;

                    case 'ltms-trigger-file-input':
                        e.preventDefault();
                        const fileInput = document.getElementById('ltms-file-input');
                        if (fileInput) fileInput.click();
                        break;
                }
            });

            // v2.9.131: Event delegation for data-tab attributes (auditor dashboard)
            $(document).on('click', '[data-tab]', function (e) {
                e.preventDefault();
                const tabId = $(this).data('tab');
                if (typeof ltmsTab === 'function') {
                    ltmsTab(this, tabId);
                } else {
                    // Inline tab switching
                    $('.ltms-tab-btn').removeClass('active');
                    $(this).addClass('active');
                    $('.ltms-tab-content').hide();
                    $('#' + tabId).show();
                }
            });
        },

        /**
         * Helper para llamadas AJAX.
         *
         * @param {string}   action   WP AJAX action.
         * @param {Object}   data     Datos adicionales.
         * @param {Function} callback Callback con la respuesta.
         */
        ajaxAction(action, data, callback) {
            $.ajax({
                url: ltmsAdmin.ajax_url,
                method: 'POST',
                data: Object.assign({ action, nonce: ltmsAdmin.nonce }, data),
                success: callback,
                error() {
                    console.error('[LTMS Admin] Error AJAX:', action);
                },
            });
        },

        /**
         * Inicializa validación de formularios de configuración.
         */
        initFormValidation() {
            $('form.ltms-settings-form').on('submit', function (e) {
                let valid = true;

                $(this).find('[required]').each(function () {
                    if (!$(this).val().trim()) {
                        $(this).addClass('ltms-field-error');
                        valid = false;
                    } else {
                        $(this).removeClass('ltms-field-error');
                    }
                });

                if (!valid) {
                    e.preventDefault();
                    LTMS.Admin.showNotice('error', 'Por favor completa todos los campos requeridos.');
                }
            });
        },

        /**
         * Toggle condicional para secciones de settings según switches.
         */
        initSettingsToggle() {
            $('[data-toggle-section]').each(function () {
                const section = $(this).data('toggle-section');
                const $target = $('[data-section="' + section + '"]');

                const updateVisibility = () => {
                    const isChecked = $(this).is(':checked') || $(this).val() === 'yes';
                    $target.toggle(isChecked);
                };

                updateVisibility(); // Estado inicial
                $(this).on('change', updateVisibility);
            });
        },

        /**
         * Inicializa tablas de datos (sort/search básico).
         */
        initDataTables() {
            $('.ltms-searchable-table').each(function () {
                const $table = $(this);
                const $input = $table.siblings('.ltms-table-search');

                $input.on('input', function () {
                    const term = $(this).val().toLowerCase().trim();
                    $table.find('tbody tr').each(function () {
                        const text = $(this).text().toLowerCase();
                        $(this).toggle(text.includes(term));
                    });
                });
            });
        },

        /**
         * Auto-dismiss de mensajes flash.
         */
        initFlashMessages() {
            $('.ltms-flash-message').each(function () {
                const $msg = $(this);
                setTimeout(() => {
                    $msg.fadeOut(600, function () { $(this).remove(); });
                }, 5000);
            });
        },

        /**
         * Reemplaza los confirm() nativos con diálogos personalizados.
         * v2.9.131: Also handles data-confirm and data-action attributes
         * (replaces inline onclick handlers removed for CSP compliance).
         */
        initConfirmDialogs() {
            // Original: data-ltms-confirm attribute
            $(document).on('click', '[data-ltms-confirm]', function (e) {
                const msg = $(this).data('ltms-confirm') || ltmsAdmin.i18n.confirm_delete;
                if (!window.confirm(msg)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            });

            // v2.9.131: data-confirm attribute (from CSP migration of onclick="return confirm()")
            $(document).on('click', '[data-confirm]', function (e) {
                const msg = $(this).data('confirm');
                if (msg && !window.confirm(msg)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            });
        },

        /**
         * Prueba la conexión con un proveedor de API externo.
         *
         * @param {string}       provider Identificador del proveedor (openpay, siigo, addi, etc.).
         * @param {jQuery}       $btn     Botón que disparó la acción.
         */
        testApiConnection(provider, $btn) {
            const originalText = $btn.text();
            const $statusIcon = $btn.closest('.ltms-api-card').find('.ltms-api-status-icon');

            $btn.prop('disabled', true).text('Probando…');
            $statusIcon.removeClass('ltms-status-ok ltms-status-error ltms-status-checking')
                       .addClass('ltms-status-checking');

            this.ajaxAction('ltms_test_api_connection', { provider }, (response) => {
                $btn.prop('disabled', false).text(originalText);

                if (response.success) {
                    $statusIcon.removeClass('ltms-status-checking ltms-status-error')
                               .addClass('ltms-status-ok');
                    $btn.closest('.ltms-api-card').find('.ltms-api-latency')
                        .text(response.data.latency_ms + ' ms');
                    this.showNotice('success', response.data.message || provider + ': conexión exitosa.');
                } else {
                    $statusIcon.removeClass('ltms-status-checking ltms-status-ok')
                               .addClass('ltms-status-error');
                    this.showNotice('error', response.data || provider + ': error de conexión.');
                }
            });
        },

        /**
         * Descongela la billetera de un vendedor.
         *
         * @param {number} vendorId ID del vendedor.
         * @param {jQuery} $btn     Botón que disparó la acción.
         */
        unfreezeWallet(vendorId, $btn) {
            if (!confirm('¿Confirma que desea descongelar la billetera de este vendedor?')) {
                return;
            }

            $btn.prop('disabled', true);

            this.ajaxAction('ltms_unfreeze_wallet', { vendor_id: vendorId }, (response) => {
                $btn.prop('disabled', false);

                if (response.success) {
                    this.showNotice('success', response.data.message || 'Billetera descongelada exitosamente.');
                    // Actualizar la fila: cambiar badge de estado y botones de acción
                    const $row = $btn.closest('tr');
                    $row.find('.ltms-wallet-status-badge')
                        .removeClass('ltms-badge-danger')
                        .addClass('ltms-badge-success')
                        .text('Activa');
                    $btn.replaceWith(
                        $('<button>')
                            .addClass('button ltms-freeze-wallet')
                            .attr('data-vendor-id', vendorId)
                            .text('Congelar')
                    );
                } else {
                    this.showNotice('error', response.data || 'No se pudo descongelar la billetera.');
                }
            });
        },

        /**
         * Muestra un aviso de admin de WordPress.
         *
         * @param {string} type    'success'|'error'|'warning'|'info'.
         * @param {string} message Mensaje a mostrar.
         */
        showNotice(type, message) {
            const $existing = $('.ltms-admin-notice');
            $existing.remove();

            const $notice = $('<div>')
                .addClass('notice notice-' + type + ' is-dismissible ltms-admin-notice')
                .html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Cerrar</span></button>');

            $('.ltms-admin-wrap h1, .wrap h1').first().after($notice);

            $notice.find('.notice-dismiss').on('click', function () {
                $notice.fadeOut(300, function () { $(this).remove(); });
            });

            $('html, body').animate({ scrollTop: $notice.offset().top - 50 }, 300);
        },
    };


        // ── Ver documentos KYC (modal) ─────────────────────────────
        $(document).on('click', '.ltms-kyc-view-docs', function(e) {
            e.preventDefault();
            var raw  = $(this).data('docs');
            var docs = (typeof raw === 'string') ? JSON.parse(raw) : raw;
            var vendorName = $(this).closest('tr').find('td:first').text().trim();

            var docLabels = {
                cedula:  'Cédula / CC',
                rut:     'RUT',
                camara:  'Cámara de Comercio',
                selfie:  'Selfie con documento',
                banco:   'Certificación Bancaria',
                nit:     'NIT'
            };

            var html = '<div style="display:flex;flex-direction:column;gap:12px;">';
            var hasAny = false;
            $.each(docLabels, function(key, label) {
                if (docs[key]) {
                    hasAny = true;
                    var url  = docs[key];
                    var ext  = url.split('.').pop().toLowerCase().split('?')[0];
                    var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;
                    html += '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;">';
                    html += '<p style="font-weight:600;margin:0 0 6px;">' + label + '</p>';
                    if (isImg) {
                        html += '<a href="' + url + '" target="_blank"><img src="' + url + '" style="max-width:100%;max-height:300px;border-radius:4px;" /></a>';
                    } else {
                        html += '<a href="' + url + '" target="_blank" class="button button-secondary">📄 Ver / Descargar</a>';
                    }
                    html += '</div>';
                }
            });
            if (!hasAny) {
                html += '<p style="color:#6b7280;">No hay documentos disponibles para este vendedor.</p>';
            }
            html += '</div>';

            // Mostrar en modal existente o crear uno simple
            if ($('#ltms-kyc-modal').length) {
                $('#ltms-modal-title').text('Documentos KYC — ' + vendorName);
                $('#ltms-modal-body').html(html);
                $('#ltms-kyc-modal').addClass('open').show();
            } else {
                // Modal fallback inline
                var $overlay = $('<div id="ltms-docs-overlay">').css({
                    position:'fixed',top:0,left:0,right:0,bottom:0,
                    background:'rgba(0,0,0,0.6)',zIndex:99999,display:'flex',
                    alignItems:'center',justifyContent:'center'
                });
                var $box = $('<div>').css({
                    background:'#fff',borderRadius:'8px',padding:'24px',
                    maxWidth:'640px',width:'90%',maxHeight:'80vh',overflowY:'auto',position:'relative'
                });
                $box.html(
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
                    '<h2 style="margin:0;font-size:16px;">Documentos KYC — ' + vendorName + '</h2>' +
                    '<button id="ltms-docs-close" style="background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>' +
                    '</div>' + html
                );
                $overlay.append($box);
                $('body').append($overlay);
                $overlay.on('click', '#ltms-docs-close, #ltms-docs-overlay', function(ev) {
                    if (ev.target === $overlay[0] || ev.target.id === 'ltms-docs-close') $overlay.remove();
                });
            }
        });

        // Cerrar modal KYC existente
        $(document).on('click', '#ltms-modal-close-btn, #ltms-modal-close-btn2', function() {
            $('#ltms-kyc-modal').removeClass('open').hide();
        });

    // ── Inicializar cuando el DOM esté listo ─────────────────────
    $(document).ready(function () {
        if (typeof ltmsAdmin !== 'undefined') {
            LTMS.Admin.init();
        }
    });

})(jQuery);
