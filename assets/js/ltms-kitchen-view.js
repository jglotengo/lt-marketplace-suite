    // v2.9.92 P2: KDS polling + audio + auto-refresh
    (function($) {
        'use strict';

        var kdsState = {
            pollInterval: null,
            soundOn: true,
            autoRefresh: true,
            lastOrderCount: 0
        };

        // Sound toggle
        $('#ltms-kds-sound-toggle').on('click', function() {
            kdsState.soundOn = !kdsState.soundOn;
            $(this).attr('data-sound-on', kdsState.soundOn ? '1' : '0');
            $('#ltms-kds-sound-label').text(kdsState.soundOn ? 'Sonido: ON' : 'Sonido: OFF');
        });

        // Auto-refresh toggle
        $('#ltms-kds-auto-refresh').on('change', function() {
            kdsState.autoRefresh = $(this).is(':checked');
            if (kdsState.autoRefresh) {
                startPolling();
            } else {
                stopPolling();
            }
        });

        // Manual refresh
        $('#ltms-kds-refresh-btn').on('click', function() {
            fetchKDSOrders();
        });

        function playSound() {
            if (!kdsState.soundOn) return;
            var audio = document.getElementById('ltms-kds-audio');
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(function() {});
            }
        }

        function startPolling() {
            stopPolling();
            kdsState.pollInterval = setInterval(fetchKDSOrders, 10000); // 10s
        }

        function stopPolling() {
            if (kdsState.pollInterval) {
                clearInterval(kdsState.pollInterval);
                kdsState.pollInterval = null;
            }
        }

        function fetchKDSOrders() {
            $.ajax({
                url: ltmsDashboard.ajax_url,
                method: 'POST',
                data: { action: 'ltms_kitchen_get_orders', nonce: ltmsDashboard.nonce },
                success: function(response) {
                    if (!response.success) return;
                    var data = response.data;
                    renderKDS(data);
                    updateClock();
                    $('#ltms-kds-last-updated').text('Actualizado: ' + new Date().toLocaleTimeString('es-CO'));
                },
                error: function() {
                    $('#ltms-kds-last-updated').text('Error de conexión');
                }
            });
        }

        function renderKDS(data) {
            var orders = data.orders || [];
            var stats = data.stats || {};

            // Update stats
            $('#ltms-kds-stat-new').text(stats.new || 0);
            $('#ltms-kds-stat-preparing').text(stats.preparing || 0);
            $('#ltms-kds-stat-ready').text(stats.ready || 0);
            $('#ltms-kds-stat-served').text(stats.served || 0);
            $('#ltms-kds-count').text(orders.length + ' pedido(s) activo(s)');

            // Play sound if new orders arrived
            if (orders.length > kdsState.lastOrderCount) {
                playSound();
            }
            kdsState.lastOrderCount = orders.length;

            // Empty state
            if (!orders.length) {
                $('#ltms-kds-grid').hide();
                $('#ltms-kds-empty').show();
                return;
            }
            $('#ltms-kds-empty').hide();
            $('#ltms-kds-grid').show();

            // Render cards
            var html = orders.map(function(o) {
                var elapsed = o.elapsed_text || '';
                var items = (o.items || []).map(function(i) {
                    return '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:0.82rem;">' +
                        '<span>' + (i.qty || 1) + 'x ' + (i.name || '') + '</span>' +
                        (i.notes ? '<span style="color:#f59e0b;font-size:0.72rem;">⚠ ' + i.notes + '</span>' : '') +
                    '</div>';
                }).join('');

                var actions = '';
                if (o.status === 'new') {
                    actions = '<button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" data-kds-action="start" data-order-id="' + o.id + '" style="width:100%;margin-top:8px;">▶ Iniciar preparación</button>';
                } else if (o.status === 'preparing') {
                    actions = '<button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm" data-kds-action="ready" data-order-id="' + o.id + '" style="width:100%;margin-top:8px;background:#16a34a;">✓ Marcar listo</button>';
                } else if (o.status === 'ready') {
                    actions = '<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" data-kds-action="serve" data-order-id="' + o.id + '" style="width:100%;margin-top:8px;">✓ Marcar servido</button>';
                }

                return '<div class="ltms-kds-card status-' + o.status + '">' +
                    '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">' +
                        '<strong>#' + o.number + '</strong>' +
                        '<span style="font-size:0.72rem;color:#9ca3af;">' + elapsed + '</span>' +
                    '</div>' +
                    '<div style="font-size:0.82rem;color:#6b7280;margin-bottom:8px;">' + (o.customer || 'Cliente') + '</div>' +
                    items +
                    actions +
                '</div>';
            }).join('');

            $('#ltms-kds-grid').html(html);

            // Bind action buttons (v2.9.99 P0-1 fix: action name + param name + value mapping)
            $('[data-kds-action]').off('click').on('click', function() {
                var uiAction = $(this).data('kds-action');
                var orderId = $(this).data('order-id');
                // Map UI action → WC kitchen status (verified against ALL_KITCHEN_STATUSES).
                var statusMap = { 'start': 'preparing', 'ready': 'ready', 'serve': 'served' };
                var newStatus = statusMap[uiAction] || '';
                if (!newStatus) return;
                $.ajax({
                    url: ltmsDashboard.ajax_url, method: 'POST',
                    data: { action: 'ltms_kitchen_update_status', nonce: ltmsDashboard.nonce, order_id: orderId, status: newStatus },
                    success: function() { fetchKDSOrders(); },
                    error: function() {
                        if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                            LTMS.UX.toastError('Error', 'No se pudo actualizar el estado.');
                        }
                    }
                });
            });
        }

        function updateClock() {
            var now = new Date();
            $('#ltms-kds-clock').text(now.toLocaleTimeString('es-CO'));
        }

        // Start polling
        startPolling();
        fetchKDSOrders();
        setInterval(updateClock, 1000);
    })(jQuery);
