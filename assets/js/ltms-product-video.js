/**
 * LTMS Product Video — play/pause handler for product gallery video.
 *
 * INTEGRATIONS-AUDIT P0 FIX (CSP compliance): extracted from inline onclick
 * + inline <script> in class-ltms-product-video.php. The previous inline
 * handler also used the deprecated IE global `event` — now uses the
 * standard Event object passed to addEventListener.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.ltms-product-video-wrap[data-ltms-video-url]').forEach(function (wrap) {
            if (wrap.dataset.ltmsVideoBound === '1') return;
            wrap.dataset.ltmsVideoBound = '1';

            wrap.addEventListener('click', function (e) {
                var overlay = wrap.querySelector('.ltms-product-video-overlay');
                var video = wrap.querySelector('video');
                if (!video) return;
                if (video.paused) {
                    video.play();
                    if (overlay) overlay.style.opacity = '0';
                } else {
                    video.pause();
                    if (overlay) overlay.style.opacity = '1';
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // Re-init after jQuery AJAX product page loads.
    if (window.jQuery) {
        jQuery(document).on('wc_fragment_refresh updated_wc_div', init);
    }
})();
