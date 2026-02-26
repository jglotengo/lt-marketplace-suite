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
                if (!confirm(ltmsAdmin.i18n.confirm_delete)) return;

                self.ajaxAction('ltms_approve_payout', { payout_id: payoutId }, (response) => {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $(this).closest('tr').fadeOut(400, function () { $(this).remove(); });
                    } else {
                        self.showNotice('error', response.data);
                    }
                }.bind(this));
            });

            // Rechazar solicitud de retiro
            $(document).on('click', '.ltms-reject-payout', function (e) {
                e.preventDefault();
                const payoutId = $(this).data('payout-id');
                const reason = prompt('Motivo del rechazo (requerido):');
                if (!reason || !reason.trim()) return;

                self.ajaxAction('ltms_reject_payout', { payout_id: payoutId, reason: reason }, (response) => {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $(this).closest('tr').fadeOut(400, function () { $(this).remove(); });
                    } else {
                        self.showNotice('error', response.data);
                    }
                }.bind(this));
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
                self.ajaxAction('ltms_approve_kyc', { kyc_id: kycId }, (response) => {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $(this).closest('tr').find('.ltms-badge').removeClass().addClass('ltms-badge ltms-badge-success').text('Aprobado');
                        $(this).closest('.ltms-actions').hide();
                    } else {
                        self.showNotice('error', response.data);
                    }
                }.bind(this));
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
         */
        initConfirmDialogs() {
            $(document).on('click', '[data-ltms-confirm]', function (e) {
                const msg = $(this).data('ltms-confirm') || ltmsAdmin.i18n.confirm_delete;
                if (!confirm(msg)) {
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

    // ── Inicializar cuando el DOM esté listo ─────────────────────
    $(document).ready(function () {
        if (typeof ltmsAdmin !== 'undefined') {
            LTMS.Admin.init();
        }
    });

})(jQuery);
