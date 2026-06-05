/**
 * LTMS Homepage Fixes JS — v2.7.1
 * Ejecuta correcciones UX en la homepage pública.
 *
 * HF-01: YouTube Facade (lazy load real)
 * HF-02: Trust bar injection
 * HF-03: Textos editoriales cortados
 * HF-04: QA/placeholder products hide
 * HF-07: Xbox iframe remove
 */
(function () {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────────── */
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function isHomePage() {
        return document.body.classList.contains('home') ||
               document.body.classList.contains('front-page') ||
               window.location.pathname === '/';
    }

    /* ══════════════════════════════════════════════════════════════
       HF-01 + HF-07: YouTube Facade
       Reemplaza todos los iframes de YouTube por thumbnails clicables.
       El segundo video (Xbox, no relacionado con el marketplace) se
       oculta completamente en lugar de convertirse en facade.
       ══════════════════════════════════════════════════════════════ */
    function fixYouTube() {
        var iframes = document.querySelectorAll('iframe[src*="youtube"], iframe[src*="youtu.be"]');
        var facadeCount = 0;

        iframes.forEach(function (iframe, index) {
            var src = iframe.src || iframe.getAttribute('data-src') || '';
            var videoId = extractYTId(src);
            if (!videoId) return;

            // Detectar si es el segundo video (Xbox / no propio del marketplace)
            // Lo identificamos por posición (index > 0) o por el contenedor padre
            var isSecondVideo = (index > 0) || isXboxVideo(iframe);

            if (isSecondVideo) {
                // HF-07: ocultar el iframe ajeno completamente
                var wrapper = iframe.closest('.wp-block-embed, .elementor-widget-video, [class*="video"]') || iframe;
                wrapper.classList.add('ltms-yt-hide');
                return;
            }

            // HF-01: convertir en facade
            var thumbUrl = 'https://i.ytimg.com/vi/' + videoId + '/hqdefault.jpg';
            var title = iframe.title || iframe.getAttribute('data-title') || 'Ver video';

            var facade = document.createElement('div');
            facade.className = 'ltms-yt-facade';
            facade.setAttribute('data-video-id', videoId);
            facade.setAttribute('role', 'button');
            facade.setAttribute('tabindex', '0');
            facade.setAttribute('aria-label', 'Reproducir: ' + title);

            facade.innerHTML =
                '<img src="' + thumbUrl + '" alt="' + escHtml(title) + '" loading="lazy" decoding="async">' +
                '<div class="ltms-yt-facade__play">' +
                    '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>' +
                '</div>' +
                '<span class="ltms-yt-facade__label">' + escHtml(title) + '</span>';

            // Al clic, cargar el iframe real
            facade.addEventListener('click', function () {
                loadYTIframe(facade, videoId, iframe);
            });
            facade.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') loadYTIframe(facade, videoId, iframe);
            });

            // Copiar dimensiones del iframe padre si las tiene
            var parent = iframe.parentNode;
            if (parent) {
                parent.insertBefore(facade, iframe);
                iframe.style.display = 'none'; // Ocultar (no remover) para no romper shortcodes
            }
            facadeCount++;
        });

        return facadeCount;
    }

    function extractYTId(url) {
        var match = url.match(/(?:youtube\.com\/(?:embed\/|watch\?v=)|youtu\.be\/)([A-Za-z0-9_\-]{11})/);
        return match ? match[1] : null;
    }

    function isXboxVideo(iframe) {
        var src = iframe.src || '';
        var title = (iframe.title || '').toLowerCase();
        var parent = iframe.parentNode;
        var parentText = parent ? (parent.textContent || '').toLowerCase() : '';
        return title.includes('xbox') || src.includes('xbox') || parentText.includes('xbox');
    }

    function loadYTIframe(facade, videoId, originalIframe) {
        var iframe = document.createElement('iframe');
        iframe.src = 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0;';
        facade.style.position = 'relative';
        facade.innerHTML = '';
        facade.appendChild(iframe);
    }

    function escHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ══════════════════════════════════════════════════════════════
       HF-02: Trust bar
       Inyecta la barra de señales de confianza justo debajo del nav
       principal si no existe ya en el DOM.
       ══════════════════════════════════════════════════════════════ */
    function injectTrustBar() {
        if (document.querySelector('.ltms-trust-bar')) return; // Ya existe

        var bar = document.createElement('div');
        bar.className = 'ltms-trust-bar';
        bar.innerHTML =
            '<div class="ltms-trust-bar__inner">' +
                '<div class="ltms-trust-bar__item">' +
                    iconSvg('lock') +
                    '<span>Pagos 100% seguros</span>' +
                '</div>' +
                '<div class="ltms-trust-bar__item">' +
                    iconSvg('truck') +
                    '<span>Envíos a todo Colombia</span>' +
                '</div>' +
                '<div class="ltms-trust-bar__item">' +
                    iconSvg('check') +
                    '<span>Vendedores verificados</span>' +
                '</div>' +
                '<div class="ltms-trust-bar__item">' +
                    iconSvg('refresh') +
                    '<span>Devoluciones garantizadas</span>' +
                '</div>' +
            '</div>';

        // Insertar después del header principal
        var header = document.querySelector('header, .site-header, #masthead, nav.navbar');
        if (header && header.parentNode) {
            header.parentNode.insertBefore(bar, header.nextSibling);
        } else {
            // Fallback: insertarlo al inicio del body
            document.body.insertBefore(bar, document.body.firstChild);
        }
    }

    function iconSvg(name) {
        var icons = {
            lock:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            truck:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="1"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/></svg>',
            check:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>'
        };
        return icons[name] || '';
    }

    /* ══════════════════════════════════════════════════════════════
       HF-04: Ocultar productos QA y placeholder en homepage
       ══════════════════════════════════════════════════════════════ */
    function hideQAProducts() {
        var products = document.querySelectorAll('.products .product, ul.products li.product');
        products.forEach(function (product) {
            var title = product.querySelector('.woocommerce-loop-product__title, h2, .product-title');
            if (!title) return;

            var text = title.textContent.toLowerCase();
            var hasQA = text.includes('qa') || text.includes('test product') || text.includes('demo ltms') || text.includes('producto demo');

            // También verificar si tiene imagen placeholder de WooCommerce
            var img = product.querySelector('img');
            var hasPlaceholder = img && (
                img.src.includes('woocommerce-placeholder') ||
                img.classList.contains('woocommerce-placeholder')
            );

            if (hasQA || hasPlaceholder) {
                product.classList.add('ltms-product-qa-hidden');
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
       HF-03: Completar textos editoriales cortados
       Detecta textos que terminan abruptamente (sin puntuación final)
       y añade clase para que CSS los muestre completos.
       ══════════════════════════════════════════════════════════════ */
    function fixTruncatedTexts() {
        // Textos conocidos cortados en la homepage actual
        var truncatedPatterns = [
            { partial: 'CON NUESTRA LÍNEA DE ARTÍCULOS PARA', full: 'con nuestra línea de artículos para cocinar' },
            { partial: 'TIENEN SU PROPIO', full: '' }, // solo limpiar el truncado
            { partial: 'SIEMPRE CON ESTILO CON NUESTROS', full: 'siempre con estilo con nuestros productos' },
            { partial: 'Y CON ESTILO EN NUESTROS', full: 'y con estilo en nuestros muebles' },
            { partial: 'Y CUÍDALA CON NUESTRAS', full: 'y cuídala con nuestras referencias de skincare' },
            { partial: 'CON LOS MEJORES', full: 'con los mejores artículos de fitness' },
        ];

        var allText = document.querySelectorAll('p, span, h3, h4');
        allText.forEach(function (el) {
            var text = el.textContent.trim();
            truncatedPatterns.forEach(function (pattern) {
                if (text.toUpperCase().includes(pattern.partial)) {
                    el.classList.add('ltms-editorial-text--fixed');
                }
            });
        });
    }

    /* ══════════════════════════════════════════════════════════════
       HF-08: Imagen "Con el apoyo de" dentro del hero slider
       Se inserta como overlay en la esquina inferior del hero/slider.
       Si el slider no existe, se inyecta justo debajo del hero.
       ══════════════════════════════════════════════════════════════ */
    function injectSupportInHero() {
        if (document.querySelector('.ltms-support-overlay')) return;

        var imgUrl = (window.ltmsData && window.ltmsData.assetsUrl)
            ? window.ltmsData.assetsUrl + 'img/con-el-apoyo.png'
            : '/wp-content/plugins/lt-marketplace-suite/assets/img/con-el-apoyo.png';

        // Buscar el contenedor del slider/hero
        var hero = document.querySelector(
            '.woocommerce-slider, .slider-wrapper, .home-slider, ' +
            '.wp-block-cover, [class*="slider"], [class*="hero"], ' +
            '.rev_slider_wrapper, .vc_row:first-of-type, ' +
            '.elementor-section:first-of-type'
        );

        var overlay = document.createElement('div');
        overlay.className = 'ltms-support-overlay';
        overlay.setAttribute('aria-label', 'Con el apoyo de nuestros aliados');
        overlay.innerHTML =
            '<img ' +
                'src="' + imgUrl + '" ' +
                'alt="Con el apoyo de: Fundación Cardioinfantil, ADRwork CHC, Alegra, Openpay by BBVA, DanPago" ' +
                'loading="lazy" ' +
                'decoding="async"' +
            '>';

        if (hero) {
            // Asegurar position relative para que el absolute funcione
            var pos = window.getComputedStyle(hero).position;
            if (pos === 'static') hero.style.position = 'relative';
            hero.appendChild(overlay);
        } else {
            // Fallback: insertar justo después del primer bloque grande de la página
            var firstSection = document.querySelector('section, .entry-content > div:first-child');
            if (firstSection && firstSection.parentNode) {
                firstSection.parentNode.insertBefore(overlay, firstSection.nextSibling);
                overlay.classList.add('ltms-support-overlay--standalone');
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════
       Init — ejecutar todo en DOMContentLoaded
       ══════════════════════════════════════════════════════════════ */
    ready(function () {
        // YouTube facade — aplica en todas las páginas (hay videos en varias)
        fixYouTube();

        // Resto solo en homepage
        if (isHomePage()) {
            injectTrustBar();
            injectSupportInHero();
            hideQAProducts();
            fixTruncatedTexts();
        }

        // QA products en cualquier página de tienda
        if (document.body.classList.contains('woocommerce') ||
            document.body.classList.contains('archive') ||
            document.body.classList.contains('shop')) {
            hideQAProducts();
        }
    });

})();
