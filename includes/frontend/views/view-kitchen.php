<?php
/**
 * Vista: Kitchen Display System (KDS)
 *
 * AUDIT-RESTAURANT-ENGINE FIX: esta vista NO existía — el PHP comentaba
 * "consumed by view-kitchen.php" pero el archivo nunca fue creado.
 * El KDS solo funcionaba si se accedía via ?tab=kds pero no había HTML.
 *
 * v2.9.92: Overhaul completo — audio element, polling automático, KPIs,
 * empty state, skeleton loading, auto-refresh indicator.
 *
 * @package LTMS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$is_restaurant = get_user_meta( $user_id, 'ltms_is_restaurant', true ) === 'yes';
?>
<div class="ltms-view-pad">
    <div class="ltms-view-header">
        <h2>🍳 <?php esc_html_e( 'Kitchen Display', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <!-- v2.9.92 P2: Sound toggle with audio element -->
            <audio id="ltms-kds-audio" preload="auto" style="display:none;">
                <source src="<?php echo esc_url( LTMS_ASSETS_URL . 'sounds/new-order.mp3' ); ?>" type="audio/mpeg">
            </audio>
            <button type="button" id="ltms-kds-sound-toggle" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                    data-sound-on="1" aria-label="<?php esc_attr_e( 'Activar/desactivar sonido', 'ltms' ); ?>">
                🔔 <span id="ltms-kds-sound-label"><?php esc_html_e( 'Sonido: ON', 'ltms' ); ?></span>
            </button>
            <button type="button" id="ltms-kds-refresh-btn" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                    aria-label="<?php esc_attr_e( 'Actualizar pedidos', 'ltms' ); ?>">
                🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
            <!-- v2.9.92 P2: Auto-refresh indicator -->
            <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#6b7280;">
                <input type="checkbox" id="ltms-kds-auto-refresh" checked style="cursor:pointer;">
                <?php esc_html_e( 'Auto', 'ltms' ); ?>
            </label>
        </div>
    </div>

    <?php if ( ! $is_restaurant ) : ?>
    <!-- v2.9.92 P2: Improved empty state -->
    <div style="padding:60px;text-align:center;background:#f9fafb;border-radius:12px;border:2px dashed #e5e7eb;">
        <div style="margin-bottom:12px;opacity:0.3;"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 2v8a3 3 0 0 0 6 0V2"/><line x1="8" y1="2" x2="8" y2="10"/><path d="M16 2v20"/><path d="M19 2c-1.5 1.5-3 4-3 7s1.5 3 3 3"/></svg></div>
        <p style="font-size:1.1rem;font-weight:600;margin:0 0 8px;color:#374151;">
            <?php esc_html_e( 'Tu cuenta no tiene el modo restaurante activado.', 'ltms' ); ?>
        </p>
        <p style="font-size:0.85rem;color:#9ca3af;margin:0;">
            <?php esc_html_e( 'Contacta al administrador para activar el Kitchen Display System.', 'ltms' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- v2.9.92 P2: Stats bar with skeleton loading -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#dc2626;" id="ltms-kds-stat-new">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🔴 <?php esc_html_e( 'Nuevos', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#d97706;" id="ltms-kds-stat-preparing">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🟡 <?php esc_html_e( 'Preparando', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#16a34a;" id="ltms-kds-stat-ready">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🟢 <?php esc_html_e( 'Listos', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#6b7280;" id="ltms-kds-stat-served">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">✓ <?php esc_html_e( 'Servidos hoy', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- KDS grid -->
    <div id="ltms-kds-grid" class="ltms-kds-grid" style="
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
        gap:16px;
        min-height:300px;
    ">
        <!-- v2.9.92 P2: Skeleton loading -->
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:#9ca3af;">
            <div style="display:inline-block;width:24px;height:24px;border:3px solid #e5e7eb;border-top:3px solid #2563eb;border-radius:50%;animation:ltms-kds-spin 1s linear infinite;margin-bottom:12px;"></div>
            <div><?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- v2.9.92 P2: Empty state -->
    <div id="ltms-kds-empty" style="display:none;text-align:center;padding:60px 20px;">
        <div style="margin-bottom:12px;opacity:0.3;"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 2v8a3 3 0 0 0 6 0V2"/><line x1="8" y1="2" x2="8" y2="10"/><path d="M16 2v20"/><path d="M19 2c-1.5 1.5-3 4-3 7s1.5 3 3 3"/></svg></div>
        <h3 style="margin:0 0 8px;color:#374151;font-size:1.1rem;"><?php esc_html_e( 'No hay pedidos activos', 'ltms' ); ?></h3>
        <p style="color:#9ca3af;margin:0;font-size:0.85rem;"><?php esc_html_e( 'Los nuevos pedidos aparecerán aquí automáticamente.', 'ltms' ); ?></p>
    </div>

    <!-- Footer bar -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;">
        <span id="ltms-kds-count">—</span>
        <span>
            🕐 <span id="ltms-kds-clock">--:--:--</span>
            · <span id="ltms-kds-last-updated">—</span>
            <!-- v2.9.92 P2: Live indicator -->
            <span id="ltms-kds-live" style="display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%;margin-left:6px;animation:ltms-kds-pulse 2s infinite;" title="<?php esc_attr_e( 'En vivo', 'ltms' ); ?>"></span>
        </span>
    </div>

    <style>
    @keyframes ltms-kds-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes ltms-kds-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
    .ltms-kds-card { background:#fff; border-radius:12px; padding:16px; border-left:4px solid #dc2626; box-shadow:0 1px 4px rgba(0,0,0,0.06); transition: all 0.2s; }
    .ltms-kds-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.1); }
    .ltms-kds-card.status-preparing { border-left-color: #d97706; }
    .ltms-kds-card.status-ready { border-left-color: #16a34a; }
    .ltms-kds-card.status-served { border-left-color: #6b7280; opacity: 0.6; }
    </style>

    <script>
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
    </script>

    <?php endif; ?>
</div>
