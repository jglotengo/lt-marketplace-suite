/* ============================================================================
   LTMS Global Catbar JS (HOME-CATBAR-2026-004) — vanilla, CSP-compliant.
   1. Reubica la barra DEBAJO del header del tema: wp_body_open la imprime al
      inicio del <body>; su lugar natural es bajo el header (mismo patrón de
      la vitrina). El header del tema (Hello Elementor) NO es sticky
      (verificado en producción), así que al escrolear la barra queda sola
      arriba — siempre visible y usable.
   2. Fade del borde derecho del track: mismo patrón data-pv-scrollable /
      data-pv-at-end de los carruseles de la home (con prefijo propio
      data-ltms-* para no colisionar con el design system).
   ============================================================================ */
(function () {
	'use strict';

	function qs(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function init() {
		var bar = qs('.ltms-catbar');
		if (!bar) return;

		/* 1. Reubicación bajo el header del tema (si existe). */
		var header = qs('.elementor-location-header, #masthead, header.site-header');
		if (header && header.parentNode && header.nextElementSibling !== bar) {
			header.parentNode.insertBefore(bar, header.nextSibling);
		}

		/* 2. Fade del borde derecho mientras quede contenido por deslizar. */
		var track = qs('.ltms-catbar__track', bar);
		if (!track) return;

		function sync() {
			var max = track.scrollWidth - track.clientWidth;
			if (max > 4) {
				track.setAttribute('data-ltms-scrollable', '1');
				if (max - track.scrollLeft < 8) {
					track.setAttribute('data-ltms-at-end', '1');
				} else {
					track.removeAttribute('data-ltms-at-end');
				}
			} else {
				track.removeAttribute('data-ltms-scrollable');
				track.removeAttribute('data-ltms-at-end');
			}
		}

		track.addEventListener('scroll', sync, { passive: true });
		window.addEventListener('resize', sync);
		sync();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
