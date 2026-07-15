/**
 * LTMS Product Tabs — size-guide modal handler.
 *
 * INTEGRATIONS-AUDIT P0 FIX (CSP compliance): extracted from inline <script>
 * in class-ltms-product-tabs.php.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        $('#ltms-size-guide-open').on('click', function () {
            $('#ltms-size-guide-modal').css('display', 'flex');
            $('body').css('overflow', 'hidden');
        });
        $('#ltms-size-guide-close').on('click', function () {
            $('#ltms-size-guide-modal').css('display', 'none');
            $('body').css('overflow', '');
        });
        $('#ltms-size-guide-modal').on('click', function (e) {
            if (e.target === this) {
                $(this).css('display', 'none');
                $('body').css('overflow', '');
            }
        });
    });
})(jQuery);
