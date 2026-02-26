/**
 * LT Marketplace Suite - Modal Module
 * Sistema de modales accesibles para el dashboard del vendedor
 * Version: 1.5.0
 */

/* global jQuery */

(function ($) {
    'use strict';

    window.LTMS = window.LTMS || {};

    /**
     * LTMS.Modal - Sistema de modales
     */
    LTMS.Modal = {

        /**
         * Abre un modal por su ID.
         *
         * @param {string} modalId ID del modal (sin #).
         */
        open(modalId) {
            const $modal = $('#' + modalId);
            if ($modal.length === 0) return;

            $modal.addClass('ltms-modal-open');
            $('body').addClass('ltms-modal-body-lock');

            // Focus trap
            $modal.find('[data-ltms-modal-close], .ltms-modal-close').first().trigger('focus');
        },

        /**
         * Cierra un modal por su ID.
         *
         * @param {string} modalId ID del modal.
         */
        close(modalId) {
            const $modal = $('#' + modalId);
            $modal.removeClass('ltms-modal-open');

            // Si no hay más modales abiertos, desbloquear el body
            if ($('.ltms-modal-open').length === 0) {
                $('body').removeClass('ltms-modal-body-lock');
            }
        },

        /**
         * Cierra todos los modales abiertos.
         */
        closeAll() {
            $('.ltms-modal-open').removeClass('ltms-modal-open');
            $('body').removeClass('ltms-modal-body-lock');
        },

        /**
         * Muestra un error dentro del modal.
         *
         * @param {string} modalId ID del modal.
         * @param {string} message Mensaje de error.
         */
        showError(modalId, message) {
            const $error = $('#' + modalId).find('.ltms-modal-error');
            if ($error.length) {
                $error.text(message).show();
            }
        },
    };

    // ── Event listeners globales ──────────────────────────────────

    // Cerrar al hacer clic en el backdrop
    $(document).on('click', '.ltms-modal-backdrop', function () {
        LTMS.Modal.closeAll();
    });

    // Cerrar con el botón de cierre
    $(document).on('click', '[data-ltms-modal-close], .ltms-modal-close', function (e) {
        e.preventDefault();
        const $modal = $(this).closest('.ltms-modal');
        if ($modal.length) {
            LTMS.Modal.close($modal.attr('id'));
        }
    });

    // Abrir modal con data-ltms-modal-open
    $(document).on('click', '[data-ltms-modal-open]', function (e) {
        e.preventDefault();
        const modalId = $(this).data('ltms-modal-open');
        LTMS.Modal.open(modalId);
    });

    // Cerrar con tecla Escape
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            LTMS.Modal.closeAll();
        }
    });

})(jQuery);
