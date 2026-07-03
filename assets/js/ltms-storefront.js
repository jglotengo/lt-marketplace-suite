/**
 * LTMS Vendor Storefront — interacciones
 * @since 2.9.2
 */
(function ($) {
	'use strict';

	/* ── Feedback botones "Agregar al carrito" ── */
	$(document).on('click', '.ltms-sf-add-to-cart.ajax_add_to_cart', function () {
		$(this).removeClass('added').addClass('loading');
	});
	$(document.body).on('added_to_cart', function (event, fragments, hash, $btn) {
		if ($btn && $btn.hasClass('ltms-sf-add-to-cart')) {
			$btn.removeClass('loading').addClass('added');
			setTimeout(function () { $btn.removeClass('added'); }, 2200);
		}
	});

	/* ── Navegar al producto al hacer clic en imagen ── */
	$(document).on('click.ltms-card-nav', '.ltms-sf-card-img-link', function (e) {
		var href = $(this).attr('href');
		if (href && href !== '#') {
			e.stopImmediatePropagation();
			window.location.href = href;
		}
	});

	/* ── Botones decorativos ── */
	$(document).on('click', '.ltms-sf-action-quickview, .ltms-sf-action-compare', function (e) {
		e.preventDefault();
	});
	$(document).on('click', '.ltms-sf-action-wishlist', function (e) {
		e.preventDefault();
		$(this).toggleClass('is-active');
	});

	/* ── Collapsible filtros del sidebar ── */
	$(document).on('click', '.ltms-sf-filter-heading', function () {
		var $btn  = $(this);
		var $body = $btn.next('.ltms-sf-filter-body');
		var expanded = $btn.attr('aria-expanded') === 'true';
		$btn.attr('aria-expanded', String(!expanded));
		$body.toggleClass('is-collapsed', expanded);
	});

	/* ── Toggle sidebar en mobile ── */
	$(document).on('click', '#ltms-sf-sidebar-toggle', function () {
		$('#ltms-sf-sidebar').toggleClass('is-open');
	});

	/* ── Reactivar imágenes lazy al volver con botón Atrás (bfcache) ── */
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) return;
		document.querySelectorAll('.ltms-sf-img-main').forEach(function (img) {
			if (img.naturalWidth === 0) { var s = img.src; img.src = ''; img.src = s; }
		});
	});

})(jQuery);
