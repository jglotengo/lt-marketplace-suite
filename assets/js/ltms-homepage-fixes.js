/**
 * LTMS Homepage Fixes JS — v2.9.1
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
        var sections = document.querySelectorAll('.elementor-section, .e-con, section');
        var found = null;
        sections.forEach(function(sec) {
            if (found) return;
            var text = sec.textContent.toLowerCase();
            if (text.includes('con el apoyo') || text.includes('cardioinfantil')) {
                found = sec;
            }
        });
        if (!found) return;
        found.classList.add('ltms-apoyo-section');
        // Aplicar fondo blanco a found y TODOS sus descendientes
        var applyWhite = function(el) {
            el.style.setProperty('background', '#fff', 'important');
            el.style.setProperty('background-color', '#fff', 'important');
            el.style.setProperty('background-image', 'none', 'important');
        };
        applyWhite(found);
        found.querySelectorAll('*').forEach(applyWhite);
        found.style.setProperty('color', '#1A1A1A', 'important');
        found.querySelectorAll('h1,h2,h3,h4,p,span,a').forEach(function(el) {
            el.style.setProperty('color', '#1A1A1A', 'important');
        });
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
    /* ══════════════════════════════════════════════════════════════
       HF-19: Mobile UX Enhancements
       Mejoras de jerarquía visual en mobile (≤768px).
       - Trust bar en grid 4 columnas compacto
       - Categorías en grid 2x2 con chip ícono
       - Cards destacados: layout horizontal
       - Grid productos: precio rojo prominente
       - Footer: links en 2 col, logos de afiliación compactos
       ══════════════════════════════════════════════════════════════ */
/**
 * HF-19 v5 — Reemplazo completo de hf19MobileEnhancements()
 * Fixes aplicados vs v4:
 *
 * FIX-A: hidePurpleBar — quitar límite txt.length; buscar por nodo hoja
 * FIX-B: applyCatGrid — apply styles también al <a> dentro de btn; aumentar retries
 * FIX-C: applyEditorialCards — permitir imgs.length === 0 (imagen de fondo Elementor)
 * FIX-D: hideQAProducts — usar también placeholder img + reforzar timing
 * FIX-E: Footer chip rosa — reset más agresivo con :not selector via JS
 * FIX-F: Footer contraste — forzar color blanco en links del footer oscuro
 */
function hf19MobileEnhancements() {
    if (window.innerWidth > 768) return;

    /* ── 1. Trust bar: reestructurar items como valor + label ─ */
    var trustItems = document.querySelectorAll('.ltms-trust-bar__item');
    var trustData = [
        { value: '+2.4k', label: 'Vendedores' },
        { value: '98%',   label: 'Satisfacción' },
        { value: '48h',   label: 'Entrega' },
        { value: '$0',    label: 'Registro' }
    ];
    trustItems.forEach(function(item, i) {
        if (!trustData[i]) return;
        item.innerHTML =
            '<strong class="ltms-tb-value">' + trustData[i].value + '</strong>' +
            '<span class="ltms-tb-label">' + trustData[i].label + '</span>';
    });

    /* ── 2. FIX-A: Ocultar barra morada — data-id exacto del DOM ─
       Diagnóstico confirmó: purpleCandidates[0].id = "7e1aeca4"
       con 3 .elementor-icon-list-item y cls: e-con-full */
    function hidePurpleBar() {
        // Estrategia 1: data-id exacto (confirmado por debug DOM)
        var byId = document.querySelector('[data-id="7e1aeca4"]');
        if (byId) byId.style.setProperty('display', 'none', 'important');

        // Estrategia 2: clase ltms-trust-bar-theme
        document.querySelectorAll('.ltms-trust-bar-theme').forEach(function(el) {
            el.style.setProperty('display', 'none', 'important');
        });

        // Estrategia 3: e-con-full con 2-5 icon-list-items sin sub-secciones
        document.querySelectorAll('.e-con-full').forEach(function(el) {
            var icons = el.querySelectorAll('.elementor-icon-list-item');
            if (icons.length >= 2 && icons.length <= 5 &&
                el.querySelectorAll('.e-con[data-id]').length === 0) {
                el.style.setProperty('display', 'none', 'important');
            }
        });

        // Estrategia 4: fondo morado en inline style
        document.querySelectorAll('.e-con, .e-con-full, .elementor-section').forEach(function(el) {
            var s = el.getAttribute('style') || '';
            if (s.includes('5B2D8E') || s.includes('5b2d8e') || s.includes('91, 45, 142')) {
                el.style.setProperty('display', 'none', 'important');
            }
            var bg = window.getComputedStyle(el).backgroundColor;
            if (bg === 'rgb(91, 45, 142)') {
                el.style.setProperty('display', 'none', 'important');
            }
        });
    }
    hidePurpleBar();
    setTimeout(hidePurpleBar, 500);
    setTimeout(hidePurpleBar, 1500);

    /* ── 3. FIX-B: Categorías grid 2×2 ─────────────────────────
       DOM real confirmado: cada botón tiene su propio data-id.
       w0parpar = "e-con-inner" → ese es el grid container.
       Estrategia: encontrar el e-con-inner que contiene ≥3 btn-widgets. */
    function applyCatGrid() {
        var allBtnWidgets = Array.from(document.querySelectorAll('.home .elementor-widget-button'));
        if (allBtnWidgets.length < 3) return false;

        // Agrupar widgets por su e-con-inner o e-con padre común
        // Subir 2 niveles: widget → e-con-full → e-con-inner
        var containerMap = {};
        allBtnWidgets.forEach(function(widget) {
            // Subir hasta encontrar un contenedor que no sea el widget mismo
            var par = widget.parentNode;   // e-con-full (la sección individual del btn)
            var cont = par && par.parentNode; // e-con-inner (el contenedor de todos)
            if (!cont) return;
            // No usar body o html
            if (cont === document.body || cont.tagName === 'HTML') return;
            var key = cont.getAttribute('data-id') || cont.className.slice(0, 50);
            if (!containerMap[key]) containerMap[key] = { container: cont, section: cont.parentNode, widgets: [] };
            containerMap[key].widgets.push(widget);
        });

        var applied = false;
        Object.keys(containerMap).forEach(function(key) {
            var entry = containerMap[key];
            var gridContainer = entry.container; // e-con-inner
            var sec = entry.section;             // parent of e-con-inner
            var widgets = entry.widgets;
            if (widgets.length < 3) return;
            // No aplicar a secciones de productos o slider
            if (gridContainer.querySelector('.woocommerce-loop-product__link, .elementor-slides')) return;
            if (gridContainer.classList.contains('ltms-hf19-catgrid-done')) return;
            gridContainer.classList.add('ltms-hf19-catgrid-done');

            gridContainer.style.setProperty('display', 'grid', 'important');
            gridContainer.style.setProperty('grid-template-columns', 'repeat(2,1fr)', 'important');
            gridContainer.style.setProperty('gap', '7px', 'important');
            gridContainer.style.setProperty('padding', '10px 12px', 'important');
            gridContainer.style.setProperty('background', '#fff', 'important');
            gridContainer.style.setProperty('flex-direction', 'unset', 'important');
            gridContainer.style.setProperty('flex-wrap', 'unset', 'important');

            if (sec) {
                sec.style.setProperty('background', '#fff', 'important');
                sec.style.setProperty('padding-left', '0', 'important');
                sec.style.setProperty('padding-right', '0', 'important');
            }

            widgets.forEach(function(widget) {
                // widget.parentNode = e-con-full (sección individual del botón)
                var widgetCell = widget.parentNode; // la celda del grid
                widgetCell.style.setProperty('width', '100%', 'important');
                widgetCell.style.setProperty('padding', '0', 'important');
                widgetCell.style.setProperty('margin', '0', 'important');
                widgetCell.style.setProperty('min-width', '0', 'important');
                widget.style.setProperty('width', '100%', 'important');

                var btn = widget.querySelector('.elementor-button');
                if (!btn) return;

                var btnStyles = [
                    ['display', 'flex'],
                    ['align-items', 'center'],
                    ['justify-content', 'center'],
                    ['gap', '8px'],
                    ['background-color', '#FFF0F0'],
                    ['background', '#FFF0F0'],
                    ['border', '0.5px solid #FFCCCC'],
                    ['border-radius', '10px'],
                    ['padding', '10px 8px'],
                    ['color', '#1A1A1A'],
                    ['font-size', '12px'],
                    ['font-weight', '500'],
                    ['width', '100%'],
                    ['box-sizing', 'border-box'],
                    ['letter-spacing', '0'],
                    ['text-transform', 'none'],
                    ['min-height', '44px'],
                    ['text-align', 'center'],
                    ['white-space', 'normal'],
                    ['box-shadow', 'none']
                ];
                btnStyles.forEach(function(s) {
                    btn.style.setProperty(s[0], s[1], 'important');
                });

                // FIX-B: también aplicar al <a> dentro del botón (Elementor pone bg inline ahí)
                var anchor = btn.tagName === 'A' ? btn : btn.querySelector('a');
                if (anchor && anchor !== btn) {
                    anchor.style.setProperty('background-color', '#FFF0F0', 'important');
                    anchor.style.setProperty('background', '#FFF0F0', 'important');
                    anchor.style.setProperty('color', '#1A1A1A', 'important');
                }

                var icon = btn.querySelector('.elementor-button-icon, i, svg');
                if (icon) {
                    icon.style.setProperty('color', '#CC1818', 'important');
                    icon.style.setProperty('font-size', '15px', 'important');
                    icon.style.setProperty('flex-shrink', '0', 'important');
                }
            });

            applied = true;
            console.log('[LTMS HF-19 v5] Cat grid:', widgets.length, 'btns');
        });
        return applied;
    }

    if (!applyCatGrid()) {
        setTimeout(applyCatGrid, 600);
        setTimeout(applyCatGrid, 1500);
        setTimeout(applyCatGrid, 3000); // retry extra por Elementor lento
    } else {
        setTimeout(applyCatGrid, 400);
        setTimeout(applyCatGrid, 1200);
    }

    /* ── 4. FIX-C: Cards editoriales — permitir 0 imgs (bg Elementor) ──
       Antes: imgs.length === 1 → falla si la imagen es background.
       Ahora: imgs.length <= 1 y requiere heads + btns. */
    function applyEditorialCards() {
        var allSections = document.querySelectorAll('.home .elementor-section, .home .e-con[data-id]');
        allSections.forEach(function(sec) {
            if (sec.classList.contains('ltms-hf19-done') || sec.classList.contains('ltms-hf19-catgrid-done')) return;
            var imgs  = sec.querySelectorAll('.elementor-widget-image img');
            var btns  = sec.querySelectorAll('.elementor-button');
            var heads = sec.querySelectorAll('.elementor-widget-heading');
            var prods = sec.querySelectorAll('.product, .woocommerce-loop-product__link');

            // FIX-C: aceptar 0 o 1 imágenes inline (puede ser bg Elementor)
            if (imgs.length > 1 || btns.length < 1 || heads.length < 1 || prods.length > 0) return;
            if (sec.querySelector('.elementor-widget-video, .elementor-widget-slides')) return;
            if (sec.querySelector('.ltms-trust-bar, .ltms-hf-stats')) return;
            // No aplicar a secciones muy grandes (tienen muchos hijos)
            var directChildren = sec.querySelectorAll('.elementor-column, .e-con-full');
            if (directChildren.length > 4) return;

            sec.classList.add('ltms-hf19-done');

            var btn = btns[0];

            // Si tiene imagen inline, layout horizontal con img izquierda
            if (imgs.length === 1) {
                var img = imgs[0];
                var imgWidget = img.closest('.elementor-widget-image');
                var imgCol = imgWidget && imgWidget.closest('.elementor-column, .e-con-full');

                sec.style.setProperty('display', 'flex', 'important');
                sec.style.setProperty('flex-direction', 'row', 'important');
                sec.style.setProperty('align-items', 'stretch', 'important');
                sec.style.setProperty('background', '#fff', 'important');
                sec.style.setProperty('border-radius', '12px', 'important');
                sec.style.setProperty('overflow', 'hidden', 'important');
                sec.style.setProperty('border', '0.5px solid rgba(0,0,0,0.08)', 'important');
                sec.style.setProperty('margin', '0 12px 8px', 'important');
                sec.style.setProperty('min-height', '90px', 'important');
                sec.style.setProperty('max-height', '115px', 'important');
                sec.style.setProperty('padding', '0', 'important');

                if (imgWidget) {
                    imgWidget.style.setProperty('width', '100px', 'important');
                    imgWidget.style.setProperty('min-width', '100px', 'important');
                    imgWidget.style.setProperty('flex-shrink', '0', 'important');
                    imgWidget.style.setProperty('overflow', 'hidden', 'important');
                    imgWidget.style.setProperty('padding', '0', 'important');
                    imgWidget.style.setProperty('margin', '0', 'important');
                    img.style.setProperty('width', '100px', 'important');
                    img.style.setProperty('height', '115px', 'important');
                    img.style.setProperty('object-fit', 'cover', 'important');
                    img.style.setProperty('display', 'block', 'important');
                    img.style.setProperty('border-radius', '0', 'important');
                }
                if (imgCol && imgCol !== imgWidget) {
                    imgCol.style.setProperty('width', '100px', 'important');
                    imgCol.style.setProperty('min-width', '100px', 'important');
                    imgCol.style.setProperty('padding', '0', 'important');
                    imgCol.style.setProperty('flex-shrink', '0', 'important');
                }

                var allCols = Array.from(sec.querySelectorAll('.elementor-column, .e-con-full'));
                allCols.forEach(function(col) {
                    if (col === imgCol) return;
                    col.style.setProperty('flex', '1', 'important');
                    col.style.setProperty('padding', '10px 12px', 'important');
                    col.style.setProperty('display', 'flex', 'important');
                    col.style.setProperty('flex-direction', 'column', 'important');
                    col.style.setProperty('justify-content', 'space-between', 'important');
                    col.style.setProperty('overflow', 'hidden', 'important');
                    col.style.setProperty('min-width', '0', 'important');
                });
            } else {
                // Sin imagen inline — layout compacto centrado con bg image
                sec.style.setProperty('background', '#fff', 'important');
                sec.style.setProperty('border-radius', '12px', 'important');
                sec.style.setProperty('margin', '0 12px 8px', 'important');
                sec.style.setProperty('padding', '12px', 'important');
                sec.style.setProperty('min-height', '80px', 'important');
            }

            heads.forEach(function(h) {
                var title = h.querySelector('.elementor-heading-title');
                if (title) {
                    title.style.setProperty('font-size', '13px', 'important');
                    title.style.setProperty('font-weight', '500', 'important');
                    title.style.setProperty('color', '#1a1a1a', 'important');
                    title.style.setProperty('margin', '0 0 3px', 'important');
                    title.style.setProperty('line-height', '1.3', 'important');
                }
            });

            var btnInlineStyles = [
                'display:inline-flex',
                'align-items:center',
                'background:#CC1818',
                'color:#fff',
                'border:none',
                'border-radius:6px',
                'padding:5px 12px',
                'font-size:11px',
                'font-weight:500',
                'width:fit-content',
                'min-width:auto',
                'letter-spacing:0',
                'text-transform:none',
                'margin-top:4px'
            ].join(';') + ';';
            btn.setAttribute('style', btnInlineStyles);
        });
    }
    applyEditorialCards();
    setTimeout(applyEditorialCards, 800);

    /* ── 5. FIX-D: QA products — ocultar con retry y múltiples selectores ─ */
    function hideQAProductsMobile() {
        // Por título
        document.querySelectorAll(
            '.woocommerce-loop-product__title, .products li.product h2, .product-title'
        ).forEach(function(title) {
            var text = (title.textContent || '').toLowerCase();
            if (text.includes('qa test') || text.includes(' qa ') ||
                text.includes('demo ltms') || text.includes('producto demo') ||
                text.includes('test product')) {
                var productLi = title.closest('li.product, .product, article.product');
                if (productLi) productLi.style.setProperty('display', 'none', 'important');
            }
        });
        // Por imagen placeholder de WooCommerce
        document.querySelectorAll('.products .product img, ul.products li img').forEach(function(img) {
            if (img.src && img.src.includes('woocommerce-placeholder')) {
                var productLi = img.closest('li.product, .product, article.product');
                if (productLi) productLi.style.setProperty('display', 'none', 'important');
            }
        });
    }
    hideQAProductsMobile();
    setTimeout(hideQAProductsMobile, 600);
    setTimeout(hideQAProductsMobile, 1500);

    /* ── 6. FIX-E: Footer chip rosa — reset agresivo ──────────── */
    function fixFooterChips() {
        var footerSelectors = [
            'footer li.menu-item',
            '.elementor-location-footer li.menu-item',
            '[data-elementor-type="footer"] li.menu-item',
            'footer .elementor-icon-list-item',
            '.elementor-location-footer .elementor-icon-list-item',
            '[data-elementor-type="footer"] .elementor-icon-list-item'
        ];
        footerSelectors.forEach(function(sel) {
            document.querySelectorAll(sel).forEach(function(li) {
                li.style.setProperty('background', 'transparent', 'important');
                li.style.setProperty('background-color', 'transparent', 'important');
                li.style.setProperty('border', 'none', 'important');
                li.style.setProperty('border-radius', '0', 'important');
                li.style.setProperty('padding', '2px 0', 'important');
                li.style.setProperty('white-space', 'normal', 'important');
                li.style.setProperty('list-style', 'none', 'important');
                li.style.setProperty('display', 'block', 'important');
                var a = li.querySelector('a');
                if (a) {
                    a.style.setProperty('background', 'transparent', 'important');
                    a.style.setProperty('background-color', 'transparent', 'important');
                    a.style.setProperty('border', 'none', 'important');
                    a.style.setProperty('border-radius', '0', 'important');
                    a.style.setProperty('padding', '0', 'important');
                    a.style.setProperty('font-size', '11px', 'important');
                    a.style.setProperty('display', 'block', 'important');
                    a.style.setProperty('line-height', '1.9', 'important');
                    a.style.setProperty('text-decoration', 'none', 'important');
                }
            });
        });
    }
    fixFooterChips();
    setTimeout(fixFooterChips, 800);

    /* ── 7. FIX-F: Footer links — color según fondo ─────────────
       El footer tiene fondo oscuro (#1a1a1a / negro). Los links
       deben ser blancos/grises claros, no heredar el color rojo. */
    function fixFooterColors() {
        var footers = document.querySelectorAll(
            'footer, .site-footer, #colophon, .elementor-location-footer, [data-elementor-type="footer"]'
        );
        footers.forEach(function(footer) {
            var bg = window.getComputedStyle(footer).backgroundColor;
            var isDark = bg === 'rgb(0,0,0)' || bg === 'rgb(26,26,26)' ||
                         bg === 'rgb(0, 0, 0)' || bg === 'rgb(26, 26, 26)' ||
                         bg.includes('0, 0, 0') || bg.includes('26, 26, 26');
            // Aplicar siempre — el footer de Lo Tengo tiene fondo oscuro
            footer.querySelectorAll('a').forEach(function(a) {
                // No tocar links que ya son botones con bg propio
                if (a.classList.contains('elementor-button')) return;
                var aBg = window.getComputedStyle(a).backgroundColor;
                if (aBg && aBg !== 'rgba(0, 0, 0, 0)' && aBg !== 'transparent') return;
                a.style.setProperty('color', '#aaaaaa', 'important');
            });
            // Textos de contacto
            footer.querySelectorAll('p, span, .elementor-icon-list-text').forEach(function(el) {
                el.style.setProperty('color', '#cccccc', 'important');
            });
        });
    }
    fixFooterColors();

    /* ── 8. Footer logos afiliación legibles ─ */
    document.querySelectorAll(
        'footer img, .elementor-location-footer img, [data-elementor-type="footer"] img'
    ).forEach(function(img) {
        var isMainLogo = img.closest('.site-branding, .ast-site-branding-wrap, .elementor-widget-site-logo');
        if (isMainLogo) return;
        img.style.setProperty('max-height', '55px', 'important');
        img.style.setProperty('max-width', '150px', 'important');
        img.style.setProperty('width', 'auto', 'important');
        img.style.setProperty('height', 'auto', 'important');
        img.style.setProperty('object-fit', 'contain', 'important');
        img.style.setProperty('display', 'block', 'important');
        img.style.setProperty('margin', '4px auto', 'important');
    });

    /* ── 9. Footer padding columnas ─ */
    document.querySelectorAll(
        'footer .elementor-column, .elementor-location-footer .elementor-column, footer .e-con-full, .elementor-location-footer .e-con-full'
    ).forEach(function(col) {
        col.style.setProperty('padding-top', '10px', 'important');
        col.style.setProperty('padding-bottom', '10px', 'important');
    });

    console.log('[LTMS HF-19] Mobile enhancements v5 aplicados');
}

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
            hideXboxNativeVideo();       // HF-07b: video Xbox nativo del tema
            hideXboxVideoByPosition();   // HF-07c: Xbox por posición DOM
            fixApoyoSection();           // HF-15: sección aliados fondo blanco
            fixRecentlyViewedTitles();   // HF-16: nombres en "Vistos hoy"
            fixFooterContrast();         // HF-17: contraste footer
            hideBlackSections();         // HF-15c: secciones negras en main
            hideApoyoSectionLate();       // HF-15e: búsqueda tardía post-Elementor
            hf19MobileEnhancements();    // HF-19: mobile UX redesign
        }

        // QA products en cualquier página de tienda
        if (document.body.classList.contains('woocommerce') ||
            document.body.classList.contains('archive') ||
            document.body.classList.contains('shop')) {
            hideQAProducts();
        }
    });

})();


    /* ══════════════════════════════════════════════════════════════
       HF-15f: Sección "Con el apoyo de" — ocultar via template ID 13592
       Confirmado via wp post list: el template "Home" tiene ID 13592.
       Elementor renderiza este template en el homepage y le asigna
       la clase .elementor-13592. La sección negra es un e-con.e-parent
       dentro de ese template. JS la detecta y aplica display:none.
       ══════════════════════════════════════════════════════════════ */
    function hideApoyoSectionLate() {
        function findAndHide() {
            // El template Home (ID 13592) se renderiza con clase .elementor-13592
            var template = document.querySelector('.elementor-13592');
            if (template) {
                // Buscar todos los e-con.e-parent dentro del template
                var sections = template.querySelectorAll('.e-con.e-parent');
                sections.forEach(function(sec) {
                    var text = (sec.textContent || '').toLowerCase();
                    var html = sec.innerHTML || '';
                    if (text.includes('con el apoyo') ||
                        text.includes('cardioinfantil') ||
                        text.includes('adrwork') ||
                        html.includes('danpago') ||
                        html.includes('alegra') ||
                        html.includes('openpay')) {
                        sec.classList.add('ltms-apoyo-hidden');
                        return true;
                    }
                });
                // Si no encontró por texto, ocultar el e-con con fondo negro
                sections.forEach(function(sec) {
                    var bg = window.getComputedStyle(sec).backgroundColor;
                    var bgImg = window.getComputedStyle(sec).backgroundImage;
                    if (bg === 'rgb(0, 0, 0)' || bg === 'rgba(0, 0, 0, 1)' ||
                        (bgImg && bgImg !== 'none' && bgImg.includes('url') && 
                         bg !== 'rgba(0, 0, 0, 0)')) {
                        // Verificar que es la sección negra (no otra con imagen)
                        var h = sec.offsetHeight;
                        if (h > 200) { // la sección negra ocupa 3+ pantallas
                            sec.classList.add('ltms-apoyo-hidden');
                        }
                    }
                });
                return true; // template encontrado
            }
            return false; // template aún no renderizado
        }

        // Intentar inmediatamente, luego con delays para Elementor lazy-load
        if (!findAndHide()) {
            setTimeout(function() {
                if (!findAndHide()) {
                    setTimeout(function() {
                        if (!findAndHide()) {
                            setTimeout(findAndHide, 3000);
                        }
                    }, 1000);
                }
            }, 500);
        } else {
            // Template ya está, pero la sección puede llegar después
            setTimeout(findAndHide, 500);
            setTimeout(findAndHide, 1500);
        }
    }

    /* ══════════════════════════════════════════════════════════════
       HF-07c: Video Xbox — ocultar el segundo widget de video
       El CSS nth-of-type solo funciona en elementos hermanos directos.
       JS lo refuerza buscando todos los widgets de video en el DOM.
       ══════════════════════════════════════════════════════════════ */
    function hideXboxVideoByPosition() {
        var videoWidgets = document.querySelectorAll(
            '.elementor-widget-video, .wp-block-video, .elementor-widget-video-playlist'
        );
        if (videoWidgets.length >= 2) {
            videoWidgets[1].classList.add('ltms-xbox-hidden');
        }
        // Fallback: ocultar cualquier video con poster que parezca gaming
        var videos = document.querySelectorAll('video[poster]');
        videos.forEach(function(v) {
            var poster = (v.getAttribute('poster') || '').toLowerCase();
            var src    = (v.getAttribute('src') || '').toLowerCase();
            if (poster.indexOf('xbox') > -1 || poster.indexOf('control') > -1 ||
                src.indexOf('xbox') > -1 || poster.indexOf('gaming') > -1) {
                var wrapper = v.closest('.elementor-widget, section, figure, .e-con');
                if (wrapper) wrapper.classList.add('ltms-xbox-hidden');
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
       HF-16: "Vistos hoy" — inyectar nombre de producto si falta
       Recorre las cards de la sección recently-viewed y se asegura
       de que el título sea visible.
       ══════════════════════════════════════════════════════════════ */
    function fixRecentlyViewedTitles() {
        var cards = document.querySelectorAll(
            '.woocommerce-recently-viewed li.product, .ltms-recently-viewed .ltms-pf-card'
        );
        cards.forEach(function(card) {
            var title = card.querySelector(
                '.woocommerce-loop-product__title, .ltms-pf-card-title, h2, h3'
            );
            if (title) {
                title.style.display      = 'block';
                title.style.overflow     = 'visible';
                title.style.whiteSpace   = 'normal';
                title.style.maxHeight    = 'none';
                title.style.visibility   = 'visible';
                title.style.opacity      = '1';
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
       HF-17: Footer — aplicar clase para contraste mejorado
       Detecta el footer del tema y le aplica la clase override.
       ══════════════════════════════════════════════════════════════ */
    function fixFooterContrast() {
        var footer = document.querySelector('footer, .site-footer, #colophon, footer.elementor-section');
        if (footer) {
            footer.classList.add('ltms-footer-override');
        }
    }


    /* ══════════════════════════════════════════════════════════════
       HF-15d: Sección negra — MutationObserver + background-image
       La sección "Con el apoyo de" es un Global Widget de Elementor
       inyectado dinámicamente DESPUÉS del DOMContentLoaded.
       Usa background-image negro (no background-color), por eso
       getComputedStyle no la detectaba en el evento ready.
       Solución: MutationObserver que la captura al insertarse.
       ══════════════════════════════════════════════════════════════ */
    function hideBlackSections() {
        var main = document.querySelector('#content, main, .site-main, .page-content');
        if (!main) return;

        function checkAndFix(el) {
            if (!el || !el.querySelectorAll) return;
            // Buscar el contenedor que tiene background-image oscuro o negro
            // Elementor lo setea como style inline en el e-con
            var style = el.getAttribute('style') || '';
            var bg = window.getComputedStyle(el).backgroundColor;
            var bgImg = window.getComputedStyle(el).backgroundImage;

            var isBlack = (
                bg === 'rgb(0, 0, 0)' ||
                bg === 'rgba(0, 0, 0, 1)' ||
                style.includes('#000') ||
                style.includes('rgb(0, 0, 0)') ||
                bgImg.includes('gradient') && bg === 'rgb(0, 0, 0)'
            );

            if (isBlack && el.closest('#content, main, .site-main, .page-content')) {
                el.classList.add('ltms-black-section-hidden');
            }
        }

        // Chequear elementos ya en el DOM
        main.querySelectorAll('.e-con.e-parent, .elementor-section').forEach(checkAndFix);

        // MutationObserver para elementos inyectados después
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType !== 1) return;
                    // El nodo mismo
                    checkAndFix(node);
                    // Sus hijos e-con
                    if (node.querySelectorAll) {
                        node.querySelectorAll('.e-con.e-parent, .elementor-section').forEach(checkAndFix);
                    }
                });
                // También chequear si se modificó el style de un nodo existente
                if (m.type === 'attributes' && m.attributeName === 'style') {
                    checkAndFix(m.target);
                }
            });
        });

        observer.observe(main, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style', 'class']
        });

        // Fallback: re-chequear 500ms y 1500ms después (Elementor lazy-load)
        setTimeout(function() {
            main.querySelectorAll('.e-con.e-parent, .elementor-section').forEach(checkAndFix);
        }, 500);
        setTimeout(function() {
            main.querySelectorAll('.e-con.e-parent, .elementor-section').forEach(checkAndFix);
            observer.disconnect(); // ya no necesitamos observar más
        }, 1500);
    }

