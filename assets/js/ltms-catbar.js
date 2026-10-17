/* ============================================================================
   LTMS Global Catbar JS (HOME-CATBAR-2026-004/007) — vanilla, CSP-compliant.
   1. Reubica la barra DEBAJO del header del tema (.elementor-location-header)
      y la pega GEOMÉTRICAMENTE bajo él (HOME-CATBAR-2026-007: Elementor Pro
      hace sticky el header — top = borde inferior del header en vivo; la
      barra NUNCA se superpone al header). La home NO pasa por aquí: su
      template renderiza la barra INLINE dentro del header sticky
      (HOME-CATBAR-2026-006) — el guard closest() lo respeta.
   2. Centra el chip de la categoría ACTIVA en el track al cargar
      (navegabilidad: el usuario ve su contexto de inmediato en archivos
      de categoría).
   3. Deslizamiento en ESCRITORIO (feedback del operador: "que se pueda
      desplazar"): rueda del mouse → scroll horizontal (solo mientras el
      track pueda avanzar — en los extremos la rueda escrolea la página)
      y arrastre con puntero (pointer events; el click del chip se
      suprime cuando fue un drag real). En touch el scroll nativo ya
      funciona.
   4. Fade del borde derecho del track (patrón data-ltms-scrollable /
      data-ltms-at-end de los carruseles de la home).
   ============================================================================ */
(function () {
	'use strict';

	function qs(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function init() {
		var bar = qs('.ltms-catbar');
		if (!bar) return;

		/* 1. Reubicación (solo páginas del tema; la home ya la trae inline
		   dentro de su header sticky — no tocarla). */
		if (!bar.closest('.pv-home-header')) {
			var header = qs('.elementor-location-header, #masthead, header.site-header');
			if (header && header.parentNode && header.nextElementSibling !== bar) {
				header.parentNode.insertBefore(bar, header.nextSibling);
			}

			/* 1b. HOME-CATBAR-2026-007 (feedback del operador: "en la tienda
			   se sobrepone al header" — Elementor Pro hace sticky el header
			   del tema): pegado GEOMÉTRICO — top = borde inferior visible del
			   header en cada frame. Header sticky → la barra se pega DEBAJO
			   (como en la home, nunca encima); header normal → sigue su borde
			   mientras escrolea y lockea a 0 cuando desaparece. Pura
			   geometría: cero detección de "es sticky". */
			var lastTop = -1;
			function syncTop() {
				if (!header) return;
				var t = Math.max(0, Math.round(header.getBoundingClientRect().bottom));
				if (t !== lastTop) {
					lastTop = t;
					bar.style.top = t + 'px';
				}
			}
			window.addEventListener('scroll', syncTop, { passive: true });
			window.addEventListener('resize', syncTop);
			syncTop();
		}

		var track = qs('.ltms-catbar__track', bar);
		if (!track) return;

		/* 2. Chip activo centrado (archivos de categoría). */
		var active = qs('.ltms-catbar__chip.is-active', track);
		if (active && active.scrollIntoView) {
			active.scrollIntoView({ block: 'nearest', inline: 'center' });
		}

		/* 3a. Rueda → scroll horizontal (escritorio). En los extremos no se
		   consume el evento: la página escrolea con normalidad. */
		track.addEventListener('wheel', function (e) {
			if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
			var max = track.scrollWidth - track.clientWidth;
			if (max <= 4) return;
			var canConsume = (e.deltaY > 0 && track.scrollLeft < max - 1) ||
				(e.deltaY < 0 && track.scrollLeft > 1);
			if (canConsume) {
				e.preventDefault();
				track.scrollLeft = Math.max(0, Math.min(max, track.scrollLeft + e.deltaY));
			}
		}, { passive: false });

		/* 3b. Arrastre con puntero (mouse; touch usa el scroll nativo). */
		var dragging = false;
		var startX = 0;
		var startScroll = 0;
		var moved = 0;

		track.addEventListener('pointerdown', function (e) {
			if (e.pointerType !== 'mouse') return;
			dragging = true;
			moved = 0;
			startX = e.clientX;
			startScroll = track.scrollLeft;
			track.classList.add('is-dragging');
		});
		window.addEventListener('pointermove', function (e) {
			if (!dragging) return;
			moved = Math.abs(e.clientX - startX);
			track.scrollLeft = startScroll - (e.clientX - startX);
		});
		window.addEventListener('pointerup', function () {
			if (!dragging) return;
			dragging = false;
			track.classList.remove('is-dragging');
		});
		/* Un drag real NO debe navegar al chip donde terminó el puntero:
		   captura del click y supresión solo cuando hubo arrastre (>6px). */
		track.addEventListener('click', function (e) {
			if (moved > 6) {
				e.preventDefault();
				e.stopPropagation();
			}
			moved = 0;
		}, true);

		/* 4. Fade del borde derecho mientras quede contenido por deslizar. */
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
