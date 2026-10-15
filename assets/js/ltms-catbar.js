/* ============================================================================
   LTMS Global Catbar JS (HOME-CATBAR-2026-004) — vanilla, CSP-compliant.
   1. Reubica la barra: en la HOME nativa la adopta DENTRO de su header
      sticky (.pv-home-header, última fila — HOME-CATBAR-2026-005: reemplaza
      la fila de chips de 003 y hereda el "nunca se oculta" del header; el
      theme header está oculto en la home y el logo oficial ya vive en el
      header, por eso el CSS esconde .ltms-catbar__home en el scope home).
      En las demás páginas va DEBAJO del header del tema (Hello Elementor —
      NO es sticky, verificado en producción, así que al escrolear la barra
      queda sola arriba: siempre visible y usable).
   2. Centra el chip de la categoría ACTIVA en el track al cargar
      (navegabilidad: en archivos de categoría el chip activo puede quedar
      fuera del área visible — así el usuario ve su contexto de inmediato).
   3. Fade del borde derecho del track: mismo patrón data-ltms-scrollable /
      data-ltms-at-end de los carruseles de la home (prefijo propio
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

		/* 1. Reubicación. */
		var homeHeader = qs('.pv-home-header');
		if (homeHeader) {
			// Home nativa: dentro del header sticky propio (última fila).
			if (bar.parentNode !== homeHeader) {
				homeHeader.appendChild(bar);
			}
		} else {
			var header = qs('.elementor-location-header, #masthead, header.site-header');
			if (header && header.parentNode && header.nextElementSibling !== bar) {
				header.parentNode.insertBefore(bar, header.nextSibling);
			}
		}

		var track = qs('.ltms-catbar__track', bar);
		if (!track) return;

		/* 2. Chip activo centrado (archivos de categoría). */
		var active = qs('.ltms-catbar__chip.is-active', track);
		if (active && active.scrollIntoView) {
			active.scrollIntoView({ block: 'nearest', inline: 'center' });
		}

		/* 3. Fade del borde derecho mientras quede contenido por deslizar. */
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
