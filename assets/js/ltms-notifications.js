/**
 * LT Marketplace Suite - Notifications Module
 * Módulo de notificaciones en tiempo real para el dashboard
 * Version: 1.5.0
 */

/* global ltmsDashboard, jQuery */

(function ($) {
    'use strict';

    window.LTMS = window.LTMS || {};

    /**
     * LTMS.Notifications - Módulo de notificaciones
     */
    LTMS.Notifications = {

        /**
         * Abre/cierra el panel de notificaciones.
         */
        togglePanel() {
            const $panel = $('.ltms-notifications-panel');
            $panel.toggleClass('open');

            if ($panel.hasClass('open')) {
                this.markAllVisible();
            }
        },

        /**
         * Marca como leídas las notificaciones visibles en el panel.
         */
        markAllVisible() {
            $('.ltms-notif-item.unread').each(function () {
                const id = $(this).data('id');
                if (id && typeof LTMS.Dashboard !== 'undefined') {
                    LTMS.Dashboard.markNotificationRead(id, $(this));
                }
            });
        },
    };

    // Evento del botón de notificaciones en el topbar
    $(document).on('click', '.ltms-topbar-notif', function () {
        LTMS.Notifications.togglePanel();
    });

    // Cerrar panel al hacer clic fuera
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.ltms-notifications-panel, .ltms-topbar-notif').length) {
            $('.ltms-notifications-panel').removeClass('open');
        }
    });

})(jQuery);
