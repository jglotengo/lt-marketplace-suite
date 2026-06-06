/**
 * LTMS Homepage Fixes JS — v2.9.0
 * Ejecuta correcciones UX en la homepage pública.
 *
 * HF-01: YouTube Facade (lazy load real)
 * HF-02: Trust bar injection
 * HF-03: Textos editoriales cortados
 * HF-04: QA/placeholder products hide
 * HF-07: Xbox iframe remove
 * HF-10: Flash sale timer fix (00:00 → contador siempre activo)
 * HF-11: Hero CTA jerarquía (fill rojo vs outline blanco)
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
        var src = iframe.src || iframe.getAttribute('data-src') || '';
        var title = (iframe.title || '').toLowerCase();
        var parent = iframe.parentNode;
        var parentText = parent ? (parent.textContent || '').toLowerCase() : '';
        return title.includes('xbox') || src.includes('xbox') || parentText.includes('xbox');
    }

    /* Ocultar video Xbox que aparece como elemento nativo del tema (no iframe) */
    function hideXboxNativeVideo() {
        // Buscar cualquier video o sección que mencione Xbox
        var allEls = document.querySelectorAll('video, [class*="video"], .elementor-widget-video, figure');
        allEls.forEach(function(el) {
            var src = (el.querySelector('source') || {}).src || '';
            var poster = el.poster || el.getAttribute('data-poster') || '';
            var text = el.textContent.toLowerCase();
            var img = el.querySelector('img');
            var imgSrc = img ? (img.src || img.getAttribute('data-src') || '') : '';

            if (src.includes('xbox') || poster.includes('xbox') ||
                text.includes('xbox') || imgSrc.includes('xbox')) {
                var wrapper = el.closest('.elementor-section, .e-con, section') || el;
                wrapper.style.setProperty('display', 'none', 'important');
            }
        });

        // También buscar por imagen de thumbnail con control Xbox (detectar por contexto visual)
        var figures = document.querySelectorAll('figure, .wp-block-video, .elementor-widget-video');
        figures.forEach(function(fig) {
            var imgs = fig.querySelectorAll('img');
            imgs.forEach(function(img) {
                var s = (img.src || img.getAttribute('data-src') || '').toLowerCase();
                if (s.includes('xbox') || s.includes('controller') || s.includes('gamepad')) {
                    var wrapper = fig.closest('.elementor-section, .e-con, section') || fig;
                    wrapper.style.setProperty('display', 'none', 'important');
                }
            });
        });
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
        if (document.querySelector('.ltms-trust-bar')) return; // Ya existe (nuestra)

        // Si el tema ya tiene una barra de confianza propia, no duplicar —
        // solo aplicarle la paleta con clase override
        var themeBars = document.querySelectorAll(
            '[class*="trust"], [class*="garantia"], [class*="benefit"], ' +
            '[class*="feature-bar"], [class*="info-bar"], [class*="top-bar"]'
        );
        for (var i = 0; i < themeBars.length; i++) {
            var t = themeBars[i].textContent.toLowerCase();
            if (t.includes('envío') || t.includes('envio') || t.includes('pago') ||
                t.includes('devoluc') || t.includes('verificad')) {
                // Barra del tema detectada — aplicar paleta logo y no inyectar otra
                themeBars[i].classList.add('ltms-trust-bar-theme');
                return;
            }
        }

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

        // HF-09: inyectar stats bar dentro del hero si aún no existe
        injectHeroStats();
    }

    /* ══════════════════════════════════════════════════════════════
       HF-09: Hero stats bar
       Inyecta la fila de estadísticas (Vendedores / Satisfacción /
       Entrega / Registro gratuito) dentro del bloque hero Elementor.
       ══════════════════════════════════════════════════════════════ */
    function injectHeroStats() {
        if (document.querySelector('.ltms-hf-stats')) return;
        if (!document.body.classList.contains('home') &&
            window.location.pathname !== '/') return;

        var stats = [
            { value: '+2.4k', label: 'Vendedores' },
            { value: '98%',   label: 'Satisfacción' },
            { value: '48h',   label: 'Entrega promedio' },
            { value: '$0',    label: 'Registro' }
        ];

        var html = '<div class="ltms-hf-stats">' +
            stats.map(function(s) {
                return '<div>' +
                    '<span class="ltms-hf-stat-value">' + s.value + '</span>' +
                    '<span class="ltms-hf-stat-label">' + s.label + '</span>' +
                '</div>';
            }).join('') +
        '</div>';

        // Buscar el hero Elementor (sección de primer nivel con fondo rojo o imagen)
        var heroSelectors = [
            '.elementor-section.elementor-top-section:first-of-type',
            '.e-con.e-parent:first-of-type',
            '.wp-block-cover:first-of-type',
            '.hero-section',
            '[class*="hero"]:first-of-type'
        ];
        var hero = null;
        for (var i = 0; i < heroSelectors.length; i++) {
            hero = document.querySelector(heroSelectors[i]);
            if (hero) break;
        }
        if (!hero) return;

        // Agregar clase de override para aplicar paleta
        hero.classList.add('ltms-hf-hero-override');

        // Insertar stats al final del hero
        var statsDiv = document.createElement('div');
        statsDiv.innerHTML = html;
        hero.appendChild(statsDiv.firstChild);
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

        var base = (window.ltmsData && window.ltmsData.assetsUrl)
            ? window.ltmsData.assetsUrl
            : '/wp-content/plugins/lt-marketplace-suite/assets/';
        var imgUrl = base + 'img/con-el-apoyo.png';

        var overlay = document.createElement('div');
        overlay.className = 'ltms-support-overlay';
        overlay.setAttribute('aria-label', 'Con el apoyo de nuestros aliados');
        overlay.innerHTML =
            '<img ' +
                'src="' + imgUrl + '" ' +
                'alt="Con el apoyo de: Fundacion Cardioinfantil, ADRwork CHC, Alegra, Openpay by BBVA, DanPago" ' +
                'width="1320" height="280" ' +
                'loading="eager" ' +
                'decoding="async" ' +
                'style="width:100%;height:auto;display:block;"' +
            '>';

        // HF-08: Estrategia multi-selector para encontrar el hero slider Elementor
        // El widget principal tiene id="elementor-element-98f6846" pero puede cambiar.
        // Buscamos por widgetType (data attr), luego por clase, luego sección completa.
        var sliderWidget =
            document.querySelector('[data-widget_type="slides.default"]') ||
            document.querySelector('[data-widget_type="slides"]') ||
            document.querySelector('.elementor-element-98f6846') ||
            document.querySelector('.elementor-widget-slides') ||
            document.querySelector('.elementor-slides-wrapper') ||
            document.querySelector('.swiper-wrapper');

        // Subir al nivel de sección Elementor contenedora para insertar después de ella
        var insertAfter = sliderWidget;
        if (insertAfter) {
            // Subir hasta .elementor-section o .e-con (contenedor de nivel superior)
            var p = insertAfter.parentElement;
            while (p && !p.classList.contains('elementor-section') && !p.classList.contains('e-con') && p.tagName !== 'MAIN') {
                insertAfter = p;
                p = p.parentElement;
            }
        }

        if (insertAfter && insertAfter.parentNode) {
            insertAfter.parentNode.insertBefore(overlay, insertAfter.nextSibling);
            console.log('[LTMS HF-08] OK - insertado despues de:', insertAfter.className.substring(0,50));
        } else {
            // Fallback: insertar antes del bloque de categorias
            var catSection = document.querySelector('.ltms-category-buttons, .product-categories, .elementor-section:nth-child(2), .e-con:nth-child(2)');
            if (catSection && catSection.parentNode) {
                catSection.parentNode.insertBefore(overlay, catSection);
                console.log('[LTMS HF-08] Fallback antes de categorias');
            } else {
                // Ultimo recurso: prepend al body
                document.body.insertBefore(overlay, document.body.firstChild);
                console.log('[LTMS HF-08] Ultimo recurso: prepend body');
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════
       HF-15: Sección "Con el apoyo de" — fondo blanco + comprimir
       El bloque ocupa 4+ pantallas con fondo negro. Lo detectamos
       por texto y le aplicamos fondo blanco + altura comprimida.
       ══════════════════════════════════════════════════════════════ */
    function fixApoyoSection() {
        var sections = document.querySelectorAll(
            '.elementor-section, .e-con, section, div[class*="section"]'
        );
        var found = null;
        sections.forEach(function(sec) {
            if (found) return;
            var text = sec.textContent.toLowerCase();
            if ((text.includes('con el apoyo') || text.includes('cardioinfantil')) &&
                !sec.closest('.ltms-apoyo-section')) {
                found = sec;
            }
        });
        if (found) {
            found.classList.add('ltms-apoyo-section');
            found.style.setProperty('background', '#fff', 'important');
            // Reducir padding interno de sub-secciones con fondo negro
            var darkKids = found.querySelectorAll('[style*="background"]');
            darkKids.forEach(function(el) {
                el.style.setProperty('background', '#fff', 'important');
            });
        }
    }

    /* ══════════════════════════════════════════════════════════════
       HF-12: Stats bar — paleta logo
       Detecta la sección Elementor con la barra de estadísticas
       (4.8/5 calificación, pedidos hoy, productos publicados) por
       su contenido de texto y le aplica fondo morado.
       ══════════════════════════════════════════════════════════════ */
    function fixStatsBar() {
        if (document.querySelector('.ltms-hf-statsbar-override')) return;

        // Buscar sección que contenga textos típicos de la stats bar
        var keywords = ['calificaci', 'pedidos hoy', 'productos publicados', 'cobertura'];
        var sections = document.querySelectorAll(
            '.elementor-section, .e-con, section, [class*="section"]'
        );

        for (var i = 0; i < sections.length; i++) {
            var text = sections[i].textContent.toLowerCase();
            var matches = keywords.filter(function(k) { return text.includes(k); });
            if (matches.length >= 2) {
                sections[i].classList.add('ltms-hf-statsbar-override');
                // Asegurarse de cubrir la sección completa
                sections[i].style.setProperty('background', '#5B2D8E', 'important');
                break;
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════
       HF-13: Sección vendedores — contraste CTAs
       Detecta la sección "Vende en Lo Tengo" por su contenido y
       aplica clase para mejorar contraste de los botones.
       ══════════════════════════════════════════════════════════════ */
    function fixVendorSectionCtas() {
        if (document.querySelector('.ltms-hf-vendor-override')) return;

        var vendorKeywords = ['vende en lo tengo', 'crear tienda', 'cómo funciona', 'kyc aprobado', 'sin mensualidad'];
        var sections = document.querySelectorAll(
            '.elementor-section, .e-con, section, [class*="section"]'
        );

        for (var i = 0; i < sections.length; i++) {
            var text = sections[i].textContent.toLowerCase();
            var matches = vendorKeywords.filter(function(k) { return text.includes(k); });
            if (matches.length >= 2) {
                sections[i].classList.add('ltms-hf-vendor-override');

                // Aplicar clases a botones directamente
                var btns = sections[i].querySelectorAll('.elementor-button, a.button, a[href*="tienda"], a[href*="vender"]');
                btns.forEach(function(btn, idx) {
                    btn.classList.add(idx === 0 ? 'ltms-vendor-cta-primary' : 'ltms-vendor-cta-secondary');
                });
                break;
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════
       El widget Elementor puede quedar en 00:00:00 si la fecha
       objetivo ya pasó. Ocultamos ese countdown y lo reemplazamos
       con un ticker propio que cuenta hacia atrás desde medianoche
       (reiniciándose cada día — simula urgencia diaria real).
       ══════════════════════════════════════════════════════════════ */
    function fixFlashSaleTimer() {
        // Buscar la barra de flash sale inyectada por Elementor / tema
        var flashBar = document.querySelector(
            '.elementor-countdown-wrapper, ' +
            '[class*="flash-sale"], ' +
            '[class*="flashsale"], ' +
            '.ltms-flash-bar'
        );
        if (!flashBar) return;

        // Si ya tiene nuestro ticker no repetir
        if (document.querySelector('.ltms-flash-ticker')) return;

        // Calcular segundos restantes hasta medianoche de hoy (zona Colombia = UTC-5)
        function getSecsToMidnight() {
            var now = new Date();
            var midnight = new Date();
            midnight.setHours(23, 59, 59, 999);
            return Math.max(0, Math.floor((midnight - now) / 1000));
        }

        function pad(n) { return String(n).padStart(2, '0'); }

        function buildTicker() {
            var el = document.createElement('span');
            el.className = 'ltms-flash-ticker';
            el.setAttribute('aria-label', 'Tiempo restante de la oferta');
            el.innerHTML =
                '<span class="ltms-flash-ticker__seg" id="ltms-hh">00</span>' +
                '<span class="ltms-flash-ticker__sep">:</span>' +
                '<span class="ltms-flash-ticker__seg" id="ltms-mm">00</span>' +
                '<span class="ltms-flash-ticker__sep">:</span>' +
                '<span class="ltms-flash-ticker__seg" id="ltms-ss">00</span>';
            return el;
        }

        var ticker = buildTicker();
        // Insertar el ticker adyacente al countdown original
        flashBar.parentNode.insertBefore(ticker, flashBar.nextSibling);

        var secs = getSecsToMidnight();

        function tick() {
            if (secs < 0) secs = getSecsToMidnight(); // reiniciar al día siguiente
            var h = Math.floor(secs / 3600);
            var m = Math.floor((secs % 3600) / 60);
            var s = secs % 60;
            var hh = document.getElementById('ltms-hh');
            var mm = document.getElementById('ltms-mm');
            var ss = document.getElementById('ltms-ss');
            if (hh) hh.textContent = pad(h);
            if (mm) mm.textContent = pad(m);
            if (ss) ss.textContent = pad(s);
            secs--;
        }

        tick(); // Primer render inmediato
        setInterval(tick, 1000);
    }

    /* ══════════════════════════════════════════════════════════════
       HF-11: Hero CTA — jerarquía visual
       El hero tiene 2 botones casi idénticos. Aplicamos clases
       para diferenciarlos: fill rojo (comprador) / outline (vendedor).
       ══════════════════════════════════════════════════════════════ */
    function fixHeroCtaHierarchy() {
        // El hero Elementor ya tiene la clase ltms-hf-hero-override
        // agregada por injectHeroStats(). El CSS de HF-11 ya actúa
        // sobre los botones dentro de esa clase.
        // Este fix hace un refuerzo explícito por si los selectores
        // :first-child/:nth-child no logran especificidad suficiente.

        var hero = document.querySelector('.ltms-hf-hero-override');
        if (!hero) return;

        var btns = hero.querySelectorAll('.elementor-button, a[href*="ofertas"], a[href*="shop"], a[href*="tienda"]');

        if (btns.length === 0) return;

        // El primer botón es el CTA principal (comprar)
        btns[0].classList.add('ltms-hero-cta-primary');

        // El segundo es el de vendedores
        if (btns[1]) btns[1].classList.add('ltms-hero-cta-secondary');
    }

    /* ══════════════════════════════════════════════════════════════
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
            fixFlashSaleTimer();   // HF-10: timer siempre activo
            fixHeroCtaHierarchy(); // HF-11: jerarquía CTAs
            fixStatsBar();         // HF-12: stats bar paleta logo
            fixVendorSectionCtas();// HF-13: contraste CTAs vendedores
            hideXboxNativeVideo(); // HF-07b: video Xbox nativo del tema
            fixApoyoSection();     // HF-15: sección aliados fondo blanco
        }

        // QA products en cualquier página de tienda
        if (document.body.classList.contains('woocommerce') ||
            document.body.classList.contains('archive') ||
            document.body.classList.contains('shop')) {
            hideQAProducts();
        }
    });

})();
