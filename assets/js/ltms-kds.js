/**
 * LTMS KDS (Kitchen Display System) JS
 * Sistema de visualización de pedidos en tiempo real para restaurantes.
 *
 * AUDIT-RESTAURANT-ENGINE FIX:
 *   1. Status flow alineado con PHP (new→preparing→ready→served).
 *   2. Field names alineados: date_created, customer_name, quantity, notes, table_number.
 *   3. Polling optimizado con since parameter para增量 updates.
 *   4. Sonido con fallback a Web Audio API si el mp3 no existe.
 *   5. Auto-pause polling when tab is inactive (battery saving).
 */

/* global ltmsKds */
'use strict';

window.LTMS = window.LTMS || {};

LTMS.KDS = (function ($) {

    const state = {
        orders: {},
        pollingInterval: null,
        soundEnabled: true,
        notificationEnabled: false,
        lastUpdated: null,
        lastSince: null,
        isVisible: true,
    };

    const config = {
        ajaxUrl:      (typeof ltmsKds !== 'undefined') ? ltmsKds.ajax_url  : '/wp-admin/admin-ajax.php',
        nonce:        (typeof ltmsKds !== 'undefined') ? ltmsKds.nonce     : '',
        vendorId:     (typeof ltmsKds !== 'undefined') ? ltmsKds.vendor_id : 0,
        pollInterval: (typeof ltmsKds !== 'undefined') ? (ltmsKds.poll_interval || 10000) : 10000,
        alertSound:   (typeof ltmsKds !== 'undefined') ? ltmsKds.alert_sound : '',
    };

    // AUDIT-RESTAURANT-ENGINE FIX: status flow alineado con PHP meta keys.
    const STATUS_CONFIG = {
        new:        { label: 'Nuevo',        color: '#dc2626', icon: '🔴', priority: 1 },
        preparing:  { label: 'Preparando',   color: '#d97706', icon: '🟡', priority: 2 },
        ready:      { label: 'Listo',        color: '#16a34a', icon: '🟢', priority: 3 },
        served:     { label: 'Servido',      color: '#6b7280', icon: '✓',  priority: 4 },
        cancelled:  { label: 'Cancelado',    color: '#9ca3af', icon: '❌', priority: 5 },
    };

    function init() {
        bindEvents();
        requestNotificationPermission();
        loadOrders(true);
        startPolling();
        updateClock();
        setInterval(updateClock, 1000);

        // AUDIT-RESTAURANT-ENGINE: pause polling when tab is hidden.
        document.addEventListener('visibilitychange', function() {
            state.isVisible = !document.hidden;
            if (document.hidden) {
                stopPolling();
            } else {
                loadOrders(true);
                startPolling();
            }
        });
    }

    function bindEvents() {
        $(document).on('click', '.ltms-kds-status-btn', onStatusChange);
        $(document).on('click', '#ltms-kds-sound-toggle', onSoundToggle);
        $(document).on('click', '#ltms-kds-refresh-btn', function () { loadOrders(true); });
    }

    function startPolling() {
        if (state.pollingInterval) return;
        state.pollingInterval = setInterval(function () {
            if (state.isVisible) loadOrders(false);
        }, config.pollInterval);
    }

    function stopPolling() {
        if (state.pollingInterval) {
            clearInterval(state.pollingInterval);
            state.pollingInterval = null;
        }
    }

    function loadOrders(showSpinner) {
        if (showSpinner) {
            $('#ltms-kds-grid').addClass('ltms-kds-loading');
        }

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_kitchen_get_orders',
                nonce: config.nonce,
                vendor_id: config.vendorId,
                since: state.lastSince || '',
            },
        })
        .done(function (res) {
            if (res.success) {
                processOrderUpdates(res.data.orders);
                state.lastUpdated = new Date();
                state.lastSince = res.data.timestamp;
                updateLastUpdatedDisplay();
            }
        })
        .always(function () {
            $('#ltms-kds-grid').removeClass('ltms-kds-loading');
        });
    }

    function processOrderUpdates(orders) {
        const currentIds = Object.keys(state.orders).map(Number);
        const newOrders = [];

        orders.forEach(function (order) {
            if (!state.orders[order.id]) {
                newOrders.push(order);
            }
            state.orders[order.id] = order;
        });

        // Remove orders no longer in the active list.
        const updatedIds = orders.map(o => o.id);
        currentIds.forEach(function (id) {
            if (!updatedIds.includes(id)) {
                delete state.orders[id];
            }
        });

        renderOrders();

        if (newOrders.length > 0) {
            playNewOrderAlert();
            if (newOrders.length === 1) {
                sendDesktopNotification('Nuevo pedido #' + newOrders[0].number, newOrders[0].items_summary);
            } else {
                sendDesktopNotification(newOrders.length + ' nuevos pedidos', 'Revisa el KDS');
            }
        }
    }

    function renderOrders() {
        const $grid = $('#ltms-kds-grid');
        $grid.empty();

        const sortedOrders = Object.values(state.orders).sort(function (a, b) {
            const priorityA = (STATUS_CONFIG[a.status] || {priority: 99}).priority;
            const priorityB = (STATUS_CONFIG[b.status] || {priority: 99}).priority;
            if (priorityA !== priorityB) return priorityA - priorityB;
            return new Date(a.date_created) - new Date(b.date_created);
        });

        if (sortedOrders.length === 0) {
            $grid.html('<div class="ltms-kds-empty"><p>Sin pedidos activos 🎉</p></div>');
            return;
        }

        sortedOrders.forEach(function (order) {
            $grid.append(renderOrderCard(order));
        });

        updateOrderCount(sortedOrders.length);
    }

    function renderOrderCard(order) {
        const statusCfg = STATUS_CONFIG[order.status] || STATUS_CONFIG.new;
        const elapsed = getElapsedMinutes(order.date_created);
        const isUrgent = elapsed > 15 && order.status === 'new';
        const isCritical = elapsed > 25 && order.status === 'new';

        const orderTypeLabel = {
            dine_in:  '🍽️ En mesa',
            takeout:  '🥡 Para llevar',
            delivery: '🚚 Domicilio',
        }[order.order_type] || '🍽️ Mesa';

        const $card = $('<div>', {
            class: 'ltms-kds-order-card ltms-kds-status-' + order.status +
                   (isUrgent ? ' ltms-kds-urgent' : '') +
                   (isCritical ? ' ltms-kds-critical' : ''),
            'data-order-id': order.id,
        });

        var itemsHtml = (order.items || []).map(function (item) {
            return '<li class="ltms-kds-item">' +
                '<span class="ltms-kds-item-qty">' + escapeHtml(String(item.quantity)) + '×</span>' +
                '<span class="ltms-kds-item-name">' + escapeHtml(item.name) + '</span>' +
                (item.notes ? '<span class="ltms-kds-item-note">📝 ' + escapeHtml(item.notes) + '</span>' : '') +
                (item.modifiers && item.modifiers.length ? '<span class="ltms-kds-item-mods">' + item.modifiers.map(function(m){ return escapeHtml(m); }).join(', ') + '</span>' : '') +
                '</li>';
        }).join('');

        var nextStatus = getNextStatus(order.status);

        $card.html(
            '<div class="ltms-kds-card-header">' +
                '<div class="ltms-kds-order-number">#' + escapeHtml(String(order.number)) + '</div>' +
                '<div class="ltms-kds-order-type">' + orderTypeLabel + '</div>' +
                '<div class="ltms-kds-elapsed ' + (isUrgent ? 'ltms-kds-elapsed-urgent' : '') + (isCritical ? ' ltms-kds-elapsed-critical' : '') + '">' + elapsed + ' min</div>' +
            '</div>' +
            '<div class="ltms-kds-status-badge" style="background:' + statusCfg.color + '">' +
                statusCfg.icon + ' ' + statusCfg.label +
            '</div>' +
            '<div class="ltms-kds-customer-info">' +
                escapeHtml(order.customer_name || 'Cliente') +
                (order.table_number ? ' · Mesa ' + escapeHtml(String(order.table_number)) : '') +
            '</div>' +
            '<ul class="ltms-kds-items-list">' + itemsHtml + '</ul>' +
            (order.notes ? '<div class="ltms-kds-order-notes">📋 ' + escapeHtml(order.notes) + '</div>' : '') +
            '<div class="ltms-kds-card-footer">' +
                (nextStatus ? '<button class="ltms-kds-status-btn ltms-btn ltms-btn-sm" data-order-id="' + order.id + '" data-new-status="' + nextStatus.key + '" style="background:' + nextStatus.color + '">' + nextStatus.icon + ' ' + nextStatus.label + '</button>' : '<span class="ltms-kds-done-badge">✓ Completado</span>') +
                '<span class="ltms-kds-order-time">' + formatTime(order.date_created) + '</span>' +
            '</div>'
        );

        return $card;
    }

    function getNextStatus(currentStatus) {
        var flow = {
            new:       { key: 'preparing', label: STATUS_CONFIG.preparing.label, icon: STATUS_CONFIG.preparing.icon, color: STATUS_CONFIG.preparing.color },
            preparing: { key: 'ready',     label: STATUS_CONFIG.ready.label,     icon: STATUS_CONFIG.ready.icon,     color: STATUS_CONFIG.ready.color },
            ready:     { key: 'served',    label: STATUS_CONFIG.served.label,    icon: STATUS_CONFIG.served.icon,    color: STATUS_CONFIG.served.color },
        };
        return flow[currentStatus] || null;
    }

    function onStatusChange(e) {
        e.stopPropagation();

        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var newStatus = $btn.data('new-status');

        $btn.prop('disabled', true).text('...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_kitchen_update_status',
                nonce: config.nonce,
                order_id: orderId,
                status: newStatus,
            },
        })
        .done(function (res) {
            if (res.success) {
                if (state.orders[orderId]) {
                    state.orders[orderId].status = newStatus;
                    renderOrders();
                }
            } else {
                $btn.prop('disabled', false).text('Error');
                if (res.data) alert(res.data);
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Error - reintentar');
        });
    }

    // ── Audio ──────────────────────────────────────────────────────

    function playNewOrderAlert() {
        if (!state.soundEnabled) return;

        if (config.alertSound) {
            var audio = new Audio(config.alertSound);
            audio.volume = 0.7;
            audio.play().catch(function () {
                playBeepFallback();
            });
        } else {
            playBeepFallback();
        }
    }

    // AUDIT-RESTAURANT-ENGINE: fallback beep via Web Audio API.
    function playBeepFallback() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.5);
        } catch (e) {
            // Web Audio not available — silent.
        }
    }

    function onSoundToggle() {
        state.soundEnabled = !state.soundEnabled;
        var $btn = $('#ltms-kds-sound-toggle');
        $btn.text(state.soundEnabled ? '🔔 Sonido: ON' : '🔕 Sonido: OFF');
        $btn.toggleClass('ltms-kds-sound-off', !state.soundEnabled);
    }

    // ── Notifications ──────────────────────────────────────────────

    function requestNotificationPermission() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (permission) {
                state.notificationEnabled = permission === 'granted';
            });
        } else {
            state.notificationEnabled = Notification.permission === 'granted';
        }
    }

    function sendDesktopNotification(title, body) {
        if (!state.notificationEnabled) return;
        new Notification(title, { body: body, tag: 'ltms-kds' });
    }

    // ── UI ─────────────────────────────────────────────────────────

    function updateClock() {
        var now = new Date();
        $('#ltms-kds-clock').text(
            now.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        );
    }

    function updateLastUpdatedDisplay() {
        if (!state.lastUpdated) return;
        var formatted = state.lastUpdated.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        $('#ltms-kds-last-updated').text('Actualizado: ' + formatted);
    }

    function updateOrderCount(count) {
        $('#ltms-kds-count').text(count + ' pedido' + (count !== 1 ? 's' : ''));
    }

    function getElapsedMinutes(dateCreated) {
        var created = new Date(dateCreated);
        var now = new Date();
        return Math.floor((now - created) / 60000);
    }

    function formatTime(dateString) {
        return new Date(dateString).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function destroy() {
        stopPolling();
        $(document).off('click', '.ltms-kds-status-btn', onStatusChange);
    }

    return { init: init, destroy: destroy, reload: function() { loadOrders(true); } };

})(jQuery);

jQuery(function ($) {
    if ($('#ltms-kds-grid').length) {
        LTMS.KDS.init();
    }
});
