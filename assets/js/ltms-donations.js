/**
 * LTMS Donations — pagination handler.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-donations.php.
 */
(function ($) {
    'use strict';
    $('.ltms-donations-page').on('click', function () {
        var page = $(this).data('page');
        var url = new URL(window.location.href);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    });
})(jQuery);
