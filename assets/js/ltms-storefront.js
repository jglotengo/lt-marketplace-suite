/**
 * LTMS Vendor Storefront — interacciones de la grilla de productos
 *
 * Usa el ciclo de eventos nativo de WooCommerce (wc-add-to-cart.js) para
 * el "Agregar al carrito" — este script solo añade feedback visual
 * (loading / added) sobre los botones .ltms-sf-add-to-cart.
 *
 * El botón de "Vista rápida" y "Comparar" quedan como placeholders sin
 * backend propio todavía (no existe ese módulo en LTMS) — solo evitan
 * el comportamiento por defecto para no romper el layout.
 *
 * @package LTMS
 * @since   2.9.0
 */
(function ($) {
	'use strict';

	$(document).on('click', '.ltms-sf-add-to-cart.ajax_add_to_cart', function () {
		$(this).removeClass('added').addClass('loading');
	});

	$(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
		if ($button && $button.hasClass('ltms-sf-add-to-cart')) {
			$button.removeClass('loading').addClass('added');
			setTimeout(function () {
				$button.removeClass('added');
			}, 2200);
		}
	});

	// Vista rápida / Comparar: sin backend propio aún — placeholder no disruptivo.
	$(document).on('click', '.ltms-sf-action-quickview, .ltms-sf-action-compare', function (e) {
		e.preventDefault();
	});

	// Wishlist: estado visual local únicamente (no persiste entre sesiones
	// ni dispositivos — falta el endpoint de wishlist en el backend de LTMS).
	$(document).on('click', '.ltms-sf-action-wishlist', function (e) {
		e.preventDefault();
		$(this).toggleClass('is-active');
	});

})(jQuery);

	// Fix: imágenes <img loading="lazy"> que quedan en blanco al volver con
	// el botón "Atrás" del navegador (restauración desde el back-forward
	// cache). Chrome no siempre re-dispara la carga de imágenes lazy que
	// ya estaban "en curso" cuando la página se congeló — el navegador las
	// restaura en un estado intermedio sin pintarlas. La señal correcta
	// para esto es el evento 'pageshow' con event.persisted === true.
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) {
			return; // navegación normal, no venía del bfcache — no hace falta nada
		}

		document.querySelectorAll('.ltms-sf-card-img img').forEach(function (img) {
			if (img.complete && img.naturalWidth > 0) {
				return; // ya cargó bien, no tocar
			}
			// Forzar al navegador a re-evaluar esta imagen como si fuera nueva:
			// reasignar el mismo src dispara un nuevo intento de carga.
			var src = img.getAttribute('src');
			if (src) {
				img.removeAttribute('loading');
				img.src = src;
			}
		});
	});

