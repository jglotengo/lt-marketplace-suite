/**
 * LTMS KDS (Kitchen Display System) JS
 * Sistema de visualización de pedidos en tiempo real para vendedores con restaurante/cocina.
 * Usa polling cada 15 segundos para actualizar pedidos pendientes.
 * Version: 1.5.0
 */

/* global ltmsKds */
'use strict';

window.LTMS = window.LTMS || {};

LTMS.KDS = (function ($) {

    // ── State ──────────────────────────────────────────────────────
    const state = {
        orders: {},          // Map<order_id, order>
        pollingInterval: null,
        soundEnabled: true,
        notificationEnabled: false,
        lastUpdated: null,
    };

    // ── Config ─────────────────────────────────────────────────────
    const config = {
        ajaxUrl:      (typeof ltmsKds !== 'undefined') ? ltmsKds.ajax_url  : '/wp-admin/admin-ajax.php',
        nonce:        (typeof ltmsKds !== 'undefined') ? ltmsKds.nonce     : '',
        vendorId:     (typeof ltmsKds !== 'undefined') ? ltmsKds.vendor_id : 0,
        pollInterval: (typeof ltmsKds !== 'undefined') ? (ltmsKds.poll_interval || 15000) : 15000,
        alertSound:   '/wp-content/plugins/lt-marketplace-suite/assets/sounds/new-order.mp3',
    };

    // ── Status Colors ──────────────────────────────────────────────
    const STATUS_CONFIG = {
        processing: { label: 'Nuevo',        color: '#dc2626', icon: '🔴', priority: 1 },
        preparing:  { label: 'Preparando',   color: '#d97706', icon: '🟡', priority: 2 },
        ready:      { label: 'Listo',        color: '#16a34a', icon: '🟢', priority: 3 },
        completed:  { label: 'Entregado',    color: '#6b7280', icon: '⚫', priority: 4 },
        cancelled:  { label: 'Cancelado',    color: '#9ca3af', icon: '❌', priority: 5 },
    };

    // ── Init ───────────────────────────────────────────────────────

    function init() {
        bindEvents();
        requestNotificationPermission();
        loadOrders();
        startPolling();
        updateClock();
        setInterval(updateClock, 1000);
    }

    function bindEvents() {
        $(document).on('click', '.ltms-kds-status-btn', onStatusChange);
        $(document).on('click', '#ltms-kds-sound-toggle', onSoundToggle);
        $(document).on('click', '#ltms-kds-refresh-btn', function () { loadOrders(true); });
        $(document).on('click', '.ltms-kds-order-card', onOrderCardClick);
        $(document).on('click', '.ltms-kds-order-detail-close', closeOrderDetail);
    }

    // ── Polling ────────────────────────────────────────────────────

    function startPolling() {
        state.pollingInterval = setInterval(function () {
            loadOrders(false);
        }, config.pollInterval);
    }

    function stopPolling() {
        if (state.pollingInterval) {
            clearInterval(state.pollingInterval);
            state.pollingInterval = null;
        }
    }

    // ── Data Loading ───────────────────────────────────────────────

    function loadOrders(showSpinner) {
        if (showSpinner) {
            $('#ltms-kds-grid').addClass('ltms-kds-loading');
        }

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_kds_get_orders',
                nonce: config.nonce,
                vendor_id: config.vendorId,
            },
        })
        .done(function (res) {
            if (res.success) {
                processOrderUpdates(res.data.orders);
                state.lastUpdated = new Date();
                updateLastUpdatedDisplay();
            }
        })
        .always(function () {
            $('#ltms-kds-grid').removeClass('ltms-kds-loading');
        });
    }

    /**
     * Detecta nuevos pedidos y actualiza la vista.
     *
     * @param {Array} orders
     */
    function processOrderUpdates(orders) {
        const currentIds = Object.keys(state.orders).map(Number);
        const newOrders  = [];

        orders.forEach(function (order) {
            if (!state.orders[order.id]) {
                newOrders.push(order);
            }
            state.orders[order.id] = order;
        });

        // Detectar pedidos que ya no existen (completados/cancelados)
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

    // ── Rendering ──────────────────────────────────────────────────

    function renderOrders() {
        const $grid = $('#ltms-kds-grid');
        $grid.empty();

        const sortedOrders = Object.values(state.orders).sort(function (a, b) {
            const priorityA = STATUS_CONFIG[a.status]?.priority || 99;
            const priorityB = STATUS_CONFIG[b.status]?.priority || 99;
            if (priorityA !== priorityB) return priorityA - priorityB;
            return new Date(a.date_created) - new Date(b.date_created);
        });

        if (sortedOrders.length === 0) {
            $grid.html('<div class="ltms-kds-empty"><p>Sin pedidos activos</p></div>');
            return;
        }

        sortedOrders.forEach(function (order) {
            $grid.append(renderOrderCard(order));
        });

        updateOrderCount(sortedOrders.length);
    }

    /**
     * Construye el HTML de una tarjeta de pedido.
     *
     * @param  {Object} order
     * @return {jQuery}
     */
    function renderOrderCard(order) {
        const statusCfg = STATUS_CONFIG[order.status] || STATUS_CONFIG.processing;
        const elapsed   = getElapsedMinutes(order.date_created);
        const isUrgent  = elapsed > 20 && order.status === 'processing';

        const $card = $('<div>', {
            class: `ltms-kds-order-card ltms-kds-status-${order.status}${isUrgent ? ' ltms-kds-urgent' : ''}`,
            'data-order-id': order.id,
        });

        const itemsHtml = (order.items || []).map(function (item) {
            return `<li class="ltms-kds-item">
                <span class="ltms-kds-item-qty">${escapeHtml(String(item.quantity))}×</span>
                <span class="ltms-kds-item-name">${escapeHtml(item.name)}</span>
                ${item.notes ? `<span class="ltms-kds-item-note">📝 ${escapeHtml(item.notes)}</span>` : ''}
            </li>`;
        }).join('');

        const nextStatus = getNextStatus(order.status);

        $card.html(`
            <div class="ltms-kds-card-header">
                <div class="ltms-kds-order-number">#${escapeHtml(String(order.number))}</div>
                <div class="ltms-kds-order-type">${escapeHtml(order.order_type || 'Mesa')}</div>
                <div class="ltms-kds-elapsed ${isUrgent ? 'ltms-kds-elapsed-urgent' : ''}">${elapsed} min</div>
            </div>

            <div class="ltms-kds-status-badge" style="background:${statusCfg.color}">
                ${statusCfg.icon} ${statusCfg.label}
            </div>

            <div class="ltms-kds-customer-info">
                ${escapeHtml(order.customer_name || 'Cliente')}
                ${order.table_number ? `· Mesa ${escapeHtml(String(order.table_number))}` : ''}
            </div>

            <ul class="ltms-kds-items-list">${itemsHtml}</ul>

            ${order.notes ? `<div class="ltms-kds-order-notes">📋 ${escapeHtml(order.notes)}</div>` : ''}

            <div class="ltms-kds-card-footer">
                ${nextStatus ? `<button class="ltms-kds-status-btn ltms-btn ltms-btn-sm"
                    data-order-id="${order.id}"
                    data-new-status="${nextStatus.key}"
                    style="background:${nextStatus.color}">
                    ${nextStatus.icon} ${nextStatus.label}
                </button>` : '<span class="ltms-kds-done-badge">✓ Completado</span>'}

                <span class="ltms-kds-order-time">${formatTime(order.date_created)}</span>
            </div>
        `);

        return $card;
    }

    /**
     * Obtiene el siguiente estado lógico.
     *
     * @param  {string}      currentStatus
     * @return {Object|null}
     */
    function getNextStatus(currentStatus) {
        const flow = {
            processing: { key: 'preparing', ...STATUS_CONFIG.preparing },
            preparing:  { key: 'ready',     ...STATUS_CONFIG.ready     },
            ready:      { key: 'completed', ...STATUS_CONFIG.completed },
        };
        return flow[currentStatus] || null;
    }

    // ── Status Change ──────────────────────────────────────────────

    function onStatusChange(e) {
        e.stopPropagation();

        const $btn     = $(this);
        const orderId  = $btn.data('order-id');
        const newStatus = $btn.data('new-status');

        $btn.prop('disabled', true).text('...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_kds_update_status',
                nonce: config.nonce,
                order_id: orderId,
                new_status: newStatus,
            },
        })
        .done(function (res) {
            if (res.success) {
                if (state.orders[orderId]) {
                    state.orders[orderId].status = newStatus;
                    renderOrders();
                }
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Error - reintentar');
        });
    }

    // ── Order Detail Modal ─────────────────────────────────────────

    function onOrderCardClick() {
        const orderId = $(this).data('order-id');
        const order   = state.orders[orderId];
        if (!order) return;

        // Simple detail panel (can be extended)
        console.log('[LTMS KDS] Order detail:', order);
    }

    function closeOrderDetail() {
        $('#ltms-kds-order-detail').slideUp(200);
    }

    // ── Audio & Notifications ──────────────────────────────────────

    function playNewOrderAlert() {
        if (!state.soundEnabled) return;

        const audio = new Audio(config.alertSound);
        audio.volume = 0.7;
        audio.play().catch(function () {
            // Blocked by browser autoplay policy — OK
        });
    }

    function onSoundToggle() {
        state.soundEnabled = !state.soundEnabled;
        const $btn = $('#ltms-kds-sound-toggle');
        $btn.text(state.soundEnabled ? '🔔 Sonido: ON' : '🔕 Sonido: OFF');
        $btn.toggleClass('ltms-kds-sound-off', !state.soundEnabled);
    }

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

        new Notification(title, {
            body,
            icon: '/wp-content/plugins/lt-marketplace-suite/assets/img/icon-192x192.png',
            tag:  'ltms-kds',
        });
    }

    // ── UI Utilities ───────────────────────────────────────────────

    function updateClock() {
        const now = new Date();
        $('#ltms-kds-clock').text(
            now.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        );
    }

    function updateLastUpdatedDisplay() {
        if (!state.lastUpdated) return;
        const formatted = state.lastUpdated.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        $('#ltms-kds-last-updated').text('Actualizado: ' + formatted);
    }

    function updateOrderCount(count) {
        $('#ltms-kds-count').text(count + ' pedido' + (count !== 1 ? 's' : ''));
    }

    function getElapsedMinutes(dateCreated) {
        const created = new Date(dateCreated);
        const now     = new Date();
        return Math.floor((now - created) / 60000);
    }

    function formatTime(dateString) {
        return new Date(dateString).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // ── Destroy ────────────────────────────────────────────────────

    function destroy() {
        stopPolling();
        $(document).off('click', '.ltms-kds-status-btn', onStatusChange);
    }

    // ── Public API ──────────────────────────────────────────────────

    return { init, destroy, reload: () => loadOrders(true) };

})(jQuery);

// Auto-init
jQuery(function () {
    if ($('#ltms-kds-grid').length) {
        LTMS.KDS.init();
    }
});
