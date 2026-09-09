/**
 * LTMS Home Slider — carrusel del home (vanilla JS) + gestión admin.
 *
 * HOME-SLIDER FIX (2026-09-08): reemplaza el widget Slides de Elementor.
 * - Frontend: carrusel con autoplay, flechas y dots.
 * - Admin: gestiona los banners (subir imágenes, CTA, orden, activo).
 */
(function () {
    'use strict';

    // ════════════════════════════════════════════════════════════════
    // FRONTEND — carrusel
    // ════════════════════════════════════════════════════════════════
    function initCarousel() {
        var root = document.querySelector('[data-ltms-hs]');
        if (!root) return;

        var track   = root.querySelector('[data-ltms-hs-track]');
        var prev    = root.querySelector('[data-ltms-hs-prev]');
        var next    = root.querySelector('[data-ltms-hs-next]');
        var dotsBox = root.querySelector('[data-ltms-hs-dots]');

        if (!track) return;
        var slides = track.children;
        if (!slides.length) return;

        var total   = slides.length;
        var current = 0;
        var autoplayMs = (typeof ltmsHomeSliderData !== 'undefined' && ltmsHomeSliderData.autoplay)
            ? ltmsHomeSliderData.autoplay : 5000;
        var timer = null;

        function goTo(index) {
            if (index >= total) index = 0;
            if (index < 0) index = total - 1;
            current = index;
            track.style.transform = 'translateX(-' + (current * 100) + '%)';

            if (dotsBox) {
                var btns = dotsBox.querySelectorAll('button');
                btns.forEach(function (b, i) {
                    b.classList.toggle('is-active', i === current);
                });
            }
        }

        function nextSlide() { goTo(current + 1); }
        function prevSlide() { goTo(current - 1); }

        function startAutoplay() {
            if (total < 2 || !autoplayMs) return;
            stopAutoplay();
            timer = setInterval(nextSlide, autoplayMs);
        }
        function stopAutoplay() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        // Dots.
        if (dotsBox && total > 1) {
            var dotsHtml = '';
            for (var i = 0; i < total; i++) {
                dotsHtml += '<button type="button" role="tab" aria-label="Banner ' + (i + 1) + '"' + (i === 0 ? ' class="is-active"' : '') + '></button>';
            }
            dotsBox.innerHTML = dotsHtml;
            dotsBox.querySelectorAll('button').forEach(function (b, i) {
                b.addEventListener('click', function () { goTo(i); startAutoplay(); });
            });
        }

        if (prev) prev.addEventListener('click', function () { prevSlide(); startAutoplay(); });
        if (next) next.addEventListener('click', function () { nextSlide(); startAutoplay(); });

        // Pausar al hover (accesibilidad).
        root.addEventListener('mouseenter', stopAutoplay);
        root.addEventListener('mouseleave', startAutoplay);

        // Soporte swipe táctil (básico).
        var startX = 0;
        root.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
        root.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 40) {
                if (dx < 0) nextSlide(); else prevSlide();
                startAutoplay();
            }
        }, { passive: true });

        goTo(0);
        startAutoplay();
    }

    // ════════════════════════════════════════════════════════════════
    // ADMIN — gestor de banners
    // ════════════════════════════════════════════════════════════════
    function initAdmin() {
        if (typeof jQuery === 'undefined') return;
        if (typeof ltmsHomeSlider === 'undefined') return;
        if (!document.getElementById('ltms-hs-list')) return;

        var $ = jQuery;
        var ajaxUrl = ltmsHomeSlider.ajax_url;
        var nonce   = ltmsHomeSlider.nonce;

        function showNotice(msg, type) {
            var $n = $('#ltms-hs-notice');
            $n.removeClass('success error').addClass(type).text(msg).show();
            setTimeout(function () { $n.fadeOut(400); }, 4000);
        }

        // Plantilla de un slide vacío (para "Añadir banner").
        function emptySlideHtml() {
            return '' +
                '<div class="ltms-hs-card" data-ltms-hs-card data-index="new">' +
                '  <div class="ltms-hs-card__thumb"><span style="color:#9ca3af;">Sin imagen</span></div>' +
                '  <div class="ltms-hs-card__fields">' +
                '    <div><label style="font-size:12px;font-weight:600;">Título / Alt</label>' +
                '      <input type="text" class="ltms-hs-field" data-field="title" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;"></div>' +
                '    <div><label style="font-size:12px;font-weight:600;">Orden</label>' +
                '      <input type="number" class="ltms-hs-field" data-field="order" value="0" min="0" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;"></div>' +
                '    <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;">Imagen Desktop (1920×600-800)</label>' +
                '      <div style="display:flex;gap:8px;align-items:center;">' +
                '        <input type="hidden" class="ltms-hs-field" data-field="image_desktop">' +
                '        <input type="text" class="ltms-hs-img-preview" readonly style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;color:#6b7280;">' +
                '        <button type="button" class="button ltms-hs-upload" data-target="image_desktop">Subir</button></div></div>' +
                '    <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;">Imagen Mobile opcional (600×600)</label>' +
                '      <div style="display:flex;gap:8px;align-items:center;">' +
                '        <input type="hidden" class="ltms-hs-field" data-field="image_mobile">' +
                '        <input type="text" class="ltms-hs-img-preview" readonly style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;color:#6b7280;">' +
                '        <button type="button" class="button ltms-hs-upload" data-target="image_mobile">Subir</button></div></div>' +
                '    <div><label style="font-size:12px;font-weight:600;">Texto del botón (CTA)</label>' +
                '      <input type="text" class="ltms-hs-field" data-field="cta_text" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;"></div>' +
                '    <div><label style="font-size:12px;font-weight:600;">URL del botón</label>' +
                '      <input type="text" class="ltms-hs-field" data-field="cta_url" placeholder="https://..." style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;"></div>' +
                '    <div style="display:flex;align-items:center;gap:8px;">' +
                '      <label style="font-size:12px;font-weight:600;margin:0;"><input type="checkbox" class="ltms-hs-field" data-field="active" checked value="1"> Activo</label></div>' +
                '    <div style="text-align:right;"><button type="button" class="button button-link-delete ltms-hs-delete" data-index="new">Eliminar banner</button></div>' +
                '  </div>' +
                '</div>';
        }

        function reindex() {
            $('#ltms-hs-list .ltms-hs-card').each(function (i) {
                $(this).attr('data-index', i);
                $(this).find('.ltms-hs-delete').attr('data-index', i);
            });
            var empty = $('#ltms-hs-empty');
            if ($('#ltms-hs-list .ltms-hs-card').length) empty.hide(); else empty.show();
        }

        function refreshThumb($card) {
            var url = $card.find('input[data-field="image_desktop"]').val();
            var $thumb = $card.find('.ltms-hs-card__thumb');
            if (url) {
                $thumb.html('<img src="' + url + '" alt="">');
            } else {
                $thumb.html('<span style="color:#9ca3af;">Sin imagen</span>');
            }
        }

        function openMedia($btn) {
            var target = $btn.data('target');
            var $card  = $btn.closest('.ltms-hs-card');
            var $hidden = $card.find('input[data-field="' + target + '"]');
            var $preview = $card.find('.ltms-hs-img-preview').filter(function () {
                return $(this).closest('div').find('input[data-field="' + target + '"]').length;
            });

            var frame = wp.media({
                title: 'Seleccionar imagen del banner',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $hidden.val(att.url);
                $preview.val(att.url);
                refreshThumb($card);
            });
            frame.open();
        }

        // Delegación de eventos.
        $('#ltms-hs-list').on('click', '.ltms-hs-upload', function (e) {
            e.preventDefault();
            openMedia($(this));
        });

        $('#ltms-hs-list').on('click', '.ltms-hs-delete', function (e) {
            e.preventDefault();
            var $card = $(this).closest('.ltms-hs-card');
            var index = $(this).data('index');

            if (index !== 'new') {
                if (!confirm('¿Eliminar este banner?')) return;
                $.post(ajaxUrl, {
                    action: 'ltms_home_slider_delete',
                    nonce: nonce,
                    index: index
                }).done(function (resp) {
                    if (resp.success) {
                        $card.remove();
                        reindex();
                        showNotice('Banner eliminado. Recuerda guardar.', 'success');
                    } else {
                        showNotice(resp.data && resp.data.message ? resp.data.message : 'Error al eliminar.', 'error');
                    }
                }).fail(function () { showNotice('Error de red.', 'error'); });
            } else {
                $card.remove();
                reindex();
            }
        });

        // "Añadir banner" — usa la Media Library al subir; el botón "Subir" abre wp.media.
        $('#ltms-hs-add').on('click', function () {
            var $empty = $('#ltms-hs-empty');
            if ($empty.length) $empty.hide();
            $('#ltms-hs-list').append(emptySlideHtml());
            reindex();
        });

        // Guardar — serializa TODOS los slides del DOM.
        $('#ltms-hs-save').on('click', function () {
            var slides = [];
            $('#ltms-hs-list .ltms-hs-card').each(function () {
                var $card = $(this);
                var slide = {
                    title: $card.find('input[data-field="title"]').val(),
                    image_desktop: $card.find('input[data-field="image_desktop"]').val(),
                    image_mobile: $card.find('input[data-field="image_mobile"]').val(),
                    cta_text: $card.find('input[data-field="cta_text"]').val(),
                    cta_url: $card.find('input[data-field="cta_url"]').val(),
                    order: $card.find('input[data-field="order"]').val(),
                    active: $card.find('input[data-field="active"]').is(':checked') ? '1' : '0'
                };
                slides.push(slide);
            });

            var $btn = $(this);
            $btn.prop('disabled', true).text('Guardando...');
            $('#ltms-hs-save-status').text('Guardando...');

            $.post(ajaxUrl, {
                action: 'ltms_home_slider_save',
                nonce: nonce,
                slides: JSON.stringify(slides)
            }).done(function (resp) {
                $btn.prop('disabled', false).html('💾 Guardar slider');
                if (resp.success) {
                    $('#ltms-hs-save-status').text('✓ ' + (resp.data.count || 0) + ' banners guardados.');
                    showNotice('Slider actualizado correctamente.', 'success');
                } else {
                    $('#ltms-hs-save-status').text('');
                    showNotice(resp.data && resp.data.message ? resp.data.message : 'Error al guardar.', 'error');
                }
            }).fail(function () {
                $btn.prop('disabled', false).html('💾 Guardar slider');
                $('#ltms-hs-save-status').text('');
                showNotice('Error de red.', 'error');
            });
        });
    }

    if (document.body.classList.contains('wp-admin')) {
        initAdmin();
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initCarousel);
        } else {
            initCarousel();
        }
    }
})();