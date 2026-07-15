/**
 * LTMS Marketing — filter, pagination, copy URL, download tracking.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-marketing.php.
 */
(function ($) {
    'use strict';

    // Filter by type
    $('.ltms-mkt-filter').on('click', function () {
        var type = $(this).data('type');
        var url = new URL(window.location.href);
        if (type) { url.searchParams.set('type', type); } else { url.searchParams.delete('type'); }
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    });

    // Pagination
    $('.ltms-mkt-page').on('click', function () {
        var page = $(this).data('page');
        var url = new URL(window.location.href);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    });

    // Copy URL to clipboard
    $('.ltms-mkt-copy-url').on('click', function () {
        var url = $(this).data('url');
        var $btn = $(this);
        var original = $btn.html();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                $btn.html('✓');
                setTimeout(function () { $btn.html(original); }, 1500);
            });
        } else {
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(url).select();
            document.execCommand('copy');
            $temp.remove();
            $btn.html('✓');
            setTimeout(function () { $btn.html(original); }, 1500);
        }
    });

    // Download tracking (fire-and-forget)
    $('.ltms-mkt-download').on('click', function () {
        var bannerId = $(this).data('banner-id');
        if (!bannerId) return;
        if (typeof ltmsDashboard === 'undefined') return;
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_track_banner_download',
            nonce: ltmsDashboard.nonce,
            banner_id: bannerId
        });
    });
})(jQuery);
