/**
 * LTMS · Plaza Viva — Frontend runtime
 * Vanilla JS (no jQuery). Namespace pattern.
 * @since 3.0.0
 *
 * Public API (window.PV):
 *   PV.ajax(action, data)            → Promise<any>
 *   PV.toast(message, opts)          → toast element
 *   PV.countdown(el, seconds, opts)  → controller {stop()}
 *   PV.skeleton(container, count, kind)
 *   PV.quickView(productId)
 *   PV.flyToCart(originEl, targetEl, opts)
 *   PV.stickyATC({ sentinel, bar })
 *   PV.tabs(rootEl)
 *   PV.accordion(rootEl)
 *   PV.qtyStepper(rootEl)
 *   PV.swatches(rootEl)
 *   PV.Shopping                     → cart namespace (counts, events)
 */
(function (window, document) {
  'use strict';

  if (window.PV && window.PV.__loaded) return;

  // Namespace: const PV = window.PV = {} — guarded with || {} for safe double-load.
  const PV = window.PV = window.PV || {};
  PV.__loaded = true;
  PV.version = '3.0.0';

  /* ── Config / i18n ──────────────────────────────────────────────────────── */
  PV.config = {
    ajaxUrl: (window.ltms_data && window.ltms_data.ajax_url) || (window.ajaxurl) || '/wp-admin/admin-ajax.php',
    nonce: (window.ltms_data && window.ltms_data.nonce) || '',
    // AUDIT-FE-CKO-004 FIX (Fase 1.7): país expuesto por wp_localize_script
    // desde PHP (LTMS_Core_Config::get_country()). Antes el checkout.php
    // inyectaba este valor via <?php echo esc_js()?> dentro del script inline
    // (rompia CSP-compliance del JS al no poder vivir en archivo externo).
    country: (window.ltms_data && window.ltms_data.country) || 'CO',
    // AUDIT-FE-SP-002 FIX (Fase 1.10): config de moneda WC expuesto por
    // wp_localize_script desde PHP. Antes el single-product.php declaraba
    // el array PHP de currency y lo inyectaba via wp_json_encode() DENTRO
    // del script-tag inline (asignándolo a un global JS).
    // Mismo patrón que country:体外 al localize, NO inline. El scope PRODUCT
    // (productScope) lee PV.config.pvCurrency para formatear el total del
    // bundle en el cliente — cae al objeto pojo-default vacío si wp_localize
    // no se invoca (página sin enqueue del design system).
    pvCurrency: (window.ltms_data && window.ltms_data.pv_currency) || {},
    cartIconSelector: '.pv-cart-icon, .ltms-sf-cart, .wc-block-mini-cart__button',
    toastDuration: 3000,
    debug: false
  };

  PV.i18n = (window.ltms_data && window.ltms_data.i18n) || {
    added_to_cart: 'Producto añadido al carrito',
    quick_view_error: 'No se pudo cargar la vista rápida',
    out_of_stock: 'Sin stock',
    added_to_wishlist: 'Añadido a favoritos',
    removed_from_wishlist: 'Quitado de favoritos',
    days: 'd', hours: 'h', mins: 'm', secs: 's',
    ended: 'Oferta finalizada',
    // AUDIT-FE-CART-009 FIX (Fase 1.6): mensaje de confirmación para vaciar carrito.
    empty_cart_confirm: '¿Vaciar todo el carrito? Esta acción no se puede deshacer.',
    empty_cart_done: 'Carrito vaciado',
    // AUDIT-FE-HC FIX (Fase 1.9): strings del help-center migrados del
    // script-tag inline (eliminando la dependencia de <?php echo esc_js()?>).
    faq_result_singular: 'resultado',
    faq_result_plural: 'resultados',
    chat_unavailable: 'El chat no está disponible en este momento. Escríbenos por WhatsApp o email.'
  };

  /* ── Internal helpers ───────────────────────────────────────────────────── */
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function on(el, ev, fn, opt) { if (el) el.addEventListener(ev, fn, opt || false); }
  function off(el, ev, fn, opt) { if (el) el.removeEventListener(ev, fn, opt || false); }
  function log() { if (PV.config.debug && window.console) console.log.apply(console, ['[PV]'].concat([].slice.call(arguments))); }
  function uid(p) { return (p || 'pv-') + Math.random().toString(36).slice(2, 9); }
  function dispatch(name, detail) {
    try { window.dispatchEvent(new CustomEvent('pv:' + name, { detail: detail || {} })); }
    catch (e) { log('dispatch failed', name, e); }
  }

  PV.utils = { qs: qs, qsa: qsa, on: on, off: off, uid: uid, dispatch: dispatch };

  /* =========================================================================
   * 1. PV.Shopping namespace — cart counts & cross-component events
   * ========================================================================= */
  PV.Shopping = (function () {
    var count = 0;

    function setCount(n) {
      n = parseInt(n, 10) || 0;
      count = n;
      qsa('[data-pv-cart-count]').forEach(function (el) {
        el.textContent = String(n);
        el.style.display = n > 0 ? '' : 'none';
      });
      dispatch('cart-count', { count: n });
    }

    function increment(delta) { setCount(count + (parseInt(delta, 10) || 1)); }

    function getCount() { return count; }

    function init() {
      var initial = qs('[data-pv-cart-count]');
      if (initial) setCount(initial.getAttribute('data-pv-cart-count') || initial.textContent);
      // Listen to WooCommerce native fragments
      on(document, 'added_to_cart', function (e, fragments, hash, btn) {
        increment(1);
        if (btn) flyFromButton(btn);
      });
      on(document, 'removed_from_cart', function () { increment(-1); });
    }

    return { setCount: setCount, increment: increment, getCount: getCount, init: init };
  })();

  /* =========================================================================
   * 2. Cart fly animation
   * ========================================================================= */
  function flyFromButton(btn) {
    var target = qs(PV.config.cartIconSelector);
    if (!target) return;
    PV.flyToCart(btn, target);
  }

  PV.flyToCart = function (originEl, targetEl, opts) {
    opts = opts || {};
    if (!originEl || !targetEl) return;
    var oRect = originEl.getBoundingClientRect();
    var tRect = targetEl.getBoundingClientRect();

    var fly = document.createElement('div');
    fly.className = 'pv-fly-item';
    fly.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';

    var sx = oRect.left + oRect.width / 2 - 24;
    var sy = oRect.top + oRect.height / 2 - 24;
    var ex = tRect.left + tRect.width / 2 - 24;
    var ey = tRect.top + tRect.height / 2 - 24;

    fly.style.left = sx + 'px';
    fly.style.top = sy + 'px';
    fly.style.transform = 'translate(0,0) scale(1)';
    document.body.appendChild(fly);

    // Double rAF so initial position paints before transition
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        var dx = ex - sx, dy = ey - sy;
        fly.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(.25) rotate(' + (opts.rotate || 35) + 'deg)';
        fly.style.opacity = '0';
      });
    });

    setTimeout(function () {
      if (fly.parentNode) fly.parentNode.removeChild(fly);
      targetEl.animate
        ? targetEl.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.25)' }, { transform: 'scale(1)' }],
            { duration: 300, easing: 'cubic-bezier(.4,0,.2,1)' })
        : null;
      if (typeof opts.onDone === 'function') opts.onDone();
    }, 620);
  };

  /* =========================================================================
   * 3. Countdown timer
   * ========================================================================= */
  PV.countdown = function (el, seconds, opts) {
    opts = opts || {};
    if (!el) return { stop: function () {} };
    var remaining = Math.max(0, parseInt(seconds, 10) || 0);
    var interval = null;
    var onEnd = opts.onEnd || function () {};

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function render() {
      var d = Math.floor(remaining / 86400);
      var h = Math.floor((remaining % 86400) / 3600);
      var m = Math.floor((remaining % 3600) / 60);
      var s = remaining % 60;
      var lbl = PV.i18n;
      if (opts.template && typeof opts.template === 'function') {
        el.innerHTML = opts.template(d, h, m, s);
        return;
      }
      var html = '';
      if (d > 0) html += '<span class="pv-countdown__item"><span class="pv-countdown__num">' + d + '</span><span class="pv-countdown__lbl">' + lbl.days + '</span></span><span class="pv-countdown__sep">:</span>';
      html += '<span class="pv-countdown__item"><span class="pv-countdown__num">' + pad(h) + '</span><span class="pv-countdown__lbl">' + lbl.hours + '</span></span><span class="pv-countdown__sep">:</span>';
      html += '<span class="pv-countdown__item"><span class="pv-countdown__num">' + pad(m) + '</span><span class="pv-countdown__lbl">' + lbl.mins + '</span></span><span class="pv-countdown__sep">:</span>';
      html += '<span class="pv-countdown__item"><span class="pv-countdown__num">' + pad(s) + '</span><span class="pv-countdown__lbl">' + lbl.secs + '</span></span>';
      el.innerHTML = html;
    }

    function tick() {
      if (remaining <= 0) {
        clearInterval(interval);
        el.innerHTML = '<span class="pv-badge pv-badge--danger">' + PV.i18n.ended + '</span>';
        onEnd(el);
        dispatch('countdown-ended', { el: el });
        return;
      }
      remaining--;
      render();
    }

    render();
    interval = setInterval(tick, 1000);
    return {
      stop: function () { clearInterval(interval); },
      getRemaining: function () { return remaining; }
    };
  };

  /* =========================================================================
   * 4. Sticky ATC observer
   * ========================================================================= */
  PV.stickyATC = function (opts) {
    opts = opts || {};
    var sentinel = typeof opts.sentinel === 'string' ? qs(opts.sentinel) : opts.sentinel;
    var bar = typeof opts.bar === 'string' ? qs(opts.bar) : opts.bar;
    if (!sentinel || !bar) return null;

    if (!('IntersectionObserver' in window)) {
      // Fallback: show on scroll past sentinel
      on(window, 'scroll', function () {
        var r = sentinel.getBoundingClientRect();
        bar.classList.toggle('is-visible', r.bottom < 0);
      }, { passive: true });
      return { destroy: function () {} };
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        bar.classList.toggle('is-visible', !e.isIntersecting && e.boundingClientRect.top < 0);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0 });

    io.observe(sentinel);
    return {
      destroy: function () { io.disconnect(); }
    };
  };

  /* =========================================================================
   * 5. Quick view modal
   * ========================================================================= */
  var modalStack = [];

  PV.quickView = function (productId, opts) {
    opts = opts || {};
    if (!productId) return Promise.reject(new Error('productId required'));

    var modal = buildModal(opts);
    document.body.appendChild(modal);
    modalStack.push(modal);
    document.body.style.overflow = 'hidden';

    var body = qs('.pv-modal__body', modal);
    body.classList.add('is-loading');
    body.innerHTML = renderSkeleton('product');

    return PV.ajax('ltms_plaza_viva_quick_view', { product_id: productId })
      .then(function (res) {
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'failed');
        var data = res.data || res;
        body.classList.remove('is-loading');
        body.innerHTML = data.html || buildFallbackProduct(data);
        // Wire up interactions inside modal
        PV.tabs(qs('.pv-tabs', body));
        PV.qtyStepper(qs('.pv-qty', body));
        PV.swatches(qs('.pv-product-card__swatches', body));
        dispatch('quickview-loaded', { modal: modal, productId: productId });
      })
      .catch(function (err) {
        log('quickview error', err);
        body.classList.remove('is-loading');
        body.innerHTML = '<div class="pv-modal__content" style="grid-column:1/-1;padding:40px;text-align:center;"><p style="color:var(--danger);font-weight:600;">' + PV.i18n.quick_view_error + '</p></div>';
        PV.toast(PV.i18n.quick_view_error, { type: 'error' });
      });
  };

  function buildModal(opts) {
    var modal = document.createElement('div');
    modal.className = 'pv-modal is-open';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML =
      '<div class="pv-modal__backdrop" data-pv-close></div>' +
      '<div class="pv-modal__dialog">' +
        '<button type="button" class="pv-modal__close" aria-label="Cerrar" data-pv-close>' +
          '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>' +
        '</button>' +
        '<div class="pv-modal__body"></div>' +
      '</div>';
    on(modal, 'click', function (e) {
      if (e.target.matches('[data-pv-close]') || e.target.closest('[data-pv-close]')) closeModal(modal);
    });
    on(document, 'keydown', function h(e) {
      if (e.key === 'Escape') { closeModal(modal); off(document, 'keydown', h); }
    });
    return modal;
  }

  function closeModal(modal) {
    if (!modal || !modal.parentNode) return;
    modal.classList.remove('is-open');
    modal.style.opacity = '0';
    setTimeout(function () {
      if (modal.parentNode) modal.parentNode.removeChild(modal);
      modalStack = modalStack.filter(function (m) { return m !== modal; });
      if (!modalStack.length) document.body.style.overflow = '';
    }, 220);
    dispatch('quickview-closed', { modal: modal });
  }

  function buildFallbackProduct(d) {
    return '<div class="pv-modal__media"><img src="' + (d.image || '') + '" alt=""></div>' +
      '<div class="pv-modal__content">' +
        '<h2>' + (d.title || '') + '</h2>' +
        '<div class="pv-stars"><span class="pv-stars__num">' + (d.rating || '0') + '</span></div>' +
        '<div class="pv-product-card__price"><span class="pv-product-card__price-now">' + (d.price || '') + '</span></div>' +
        '<p style="color:var(--text-2);margin-top:14px;">' + (d.short_desc || '') + '</p>' +
        '<button type="button" class="pv-btn pv-btn--block mt-3" data-pv-add-to-cart="' + (d.id || '') + '">Agregar al carrito</button>' +
      '</div>';
  }

  /* =========================================================================
   * 6. Tabs handler
   * ========================================================================= */
  PV.tabs = function (root) {
    if (!root) return;
    var tabs = qsa('.pv-tab', root);
    var panels = qsa('.pv-tabpanel', root);
    if (!tabs.length) return;

    tabs.forEach(function (tab) {
      if (tab.__pvBound) return;
      tab.__pvBound = true;
      on(tab, 'click', function () {
        var id = tab.getAttribute('aria-controls') || tab.getAttribute('data-target');
        tabs.forEach(function (t) { t.setAttribute('aria-selected', 'false'); t.setAttribute('tabindex', '-1'); });
        tab.setAttribute('aria-selected', 'true');
        tab.setAttribute('tabindex', '0');
        panels.forEach(function (p) {
          var pid = p.id || p.getAttribute('data-panel');
          p.hidden = (pid !== id);
        });
        dispatch('tab-changed', { tab: tab, id: id });
      });
      on(tab, 'keydown', function (e) {
        var idx = tabs.indexOf(tab);
        if (e.key === 'ArrowRight') { e.preventDefault(); tabs[(idx + 1) % tabs.length].focus(); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); tabs[(idx - 1 + tabs.length) % tabs.length].focus(); }
      });
    });
  };

  /* =========================================================================
   * 7. Accordion handler
   * ========================================================================= */
  PV.accordion = function (root) {
    root = root || document;
    var heads = qsa('.pv-accordion__head', root);
    heads.forEach(function (head) {
      if (head.__pvBound) return;
      head.__pvBound = true;
      var parent = head.closest('.pv-accordion');
      var body = parent ? qs('.pv-accordion__body', parent) : null;
      if (!body) return;
      var open = parent.hasAttribute('open');
      body.hidden = !open;
      head.setAttribute('aria-expanded', String(open));
      on(head, 'click', function () {
        open = !open;
        if (open) parent.setAttribute('open', ''); else parent.removeAttribute('open');
        body.hidden = !open;
        head.setAttribute('aria-expanded', String(open));
        if (open) {
          body.style.maxHeight = '0';
          requestAnimationFrame(function () {
            body.style.maxHeight = body.scrollHeight + 'px';
            setTimeout(function () { body.style.maxHeight = ''; }, 320);
          });
        }
        dispatch('accordion-toggle', { head: head, open: open });
      });
    });
  };

  /* =========================================================================
   * 8. Quantity stepper
   * ========================================================================= */
  PV.qtyStepper = function (root) {
    root = root || document;
    var steppers = qsa('.pv-qty', root);
    steppers.forEach(function (s) {
      if (s.__pvBound) return;
      s.__pvBound = true;
      var input = qs('.pv-qty__input', s);
      var minus = qs('[data-pv-qty="minus"]', s);
      var plus = qs('[data-pv-qty="plus"]', s);
      if (!input) return;
      var min = parseFloat(input.getAttribute('min')) || 0;
      var max = parseFloat(input.getAttribute('max')) || Infinity;
      var step = parseFloat(input.getAttribute('step')) || 1;

      function setVal(v) {
        v = Math.max(min, Math.min(max, parseFloat(v) || min));
        input.value = v;
        if (minus) minus.disabled = (v <= min);
        if (plus) plus.disabled = (v >= max);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        dispatch('qty-change', { input: input, value: v });
      }

      on(minus, 'click', function () { setVal(parseFloat(input.value) - step); });
      on(plus, 'click', function () { setVal(parseFloat(input.value) + step); });
      on(input, 'change', function () { setVal(input.value); });
      on(input, 'keydown', function (e) {
        if (e.key === 'ArrowUp') { e.preventDefault(); setVal(parseFloat(input.value) + step); }
        if (e.key === 'ArrowDown') { e.preventDefault(); setVal(parseFloat(input.value) - step); }
      });
      setVal(input.value);
    });
  };

  /* =========================================================================
   * 9. Toast notification system
   * ========================================================================= */
  var toastWrap = null;
  function getToastWrap() {
    if (toastWrap && toastWrap.parentNode) return toastWrap;
    toastWrap = qs('.pv-toast-wrap');
    if (!toastWrap) {
      toastWrap = document.createElement('div');
      toastWrap.className = 'pv-toast-wrap';
      toastWrap.setAttribute('aria-live', 'polite');
      toastWrap.setAttribute('aria-atomic', 'true');
      document.body.appendChild(toastWrap);
    }
    return toastWrap;
  }

  var toastIcons = {
    success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
    warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
    info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
  };

  PV.toast = function (message, opts) {
    opts = opts || {};
    var type = opts.type || 'info';
    var title = opts.title || '';
    var duration = opts.duration != null ? opts.duration : PV.config.toastDuration;

    var wrap = getToastWrap();
    var el = document.createElement('div');
    el.className = 'pv-toast pv-toast--' + type;
    el.setAttribute('role', 'status');
    el.innerHTML =
      '<span class="pv-toast__icon" style="color:var(--' + (type === 'success' ? 'accent' : type === 'error' ? 'danger' : type === 'warning' ? 'warn' : 'primary') + ')">' +
        (toastIcons[type] || toastIcons.info) +
      '</span>' +
      '<div class="pv-toast__body">' +
        (title ? '<span class="pv-toast__title">' + escapeHtml(title) + '</span>' : '') +
        '<span>' + (typeof message === 'string' ? escapeHtml(message) : '') + '</span>' +
      '</div>' +
      '<button type="button" class="pv-toast__close" aria-label="Cerrar">×</button>';
    wrap.appendChild(el);

    var timer = null;
    function close() {
      if (timer) clearTimeout(timer);
      el.classList.add('is-leaving');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 240);
    }
    on(qs('.pv-toast__close', el), 'click', close);
    if (duration > 0) timer = setTimeout(close, duration);
    dispatch('toast-shown', { el: el, type: type, message: message });
    return { el: el, close: close };
  };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* =========================================================================
   * 10. Skeleton loading helper
   * ========================================================================= */
  PV.skeleton = function (container, count, kind) {
    if (!container) return;
    kind = kind || 'card';
    count = count || 1;
    var html = '';
    for (var i = 0; i < count; i++) html += renderSkeleton(kind);
    container.innerHTML = html;
    container.setAttribute('data-pv-skeleton', '1');
    return {
      clear: function () { container.removeAttribute('data-pv-skeleton'); container.innerHTML = ''; }
    };
  };

  function renderSkeleton(kind) {
    switch (kind) {
      case 'product':
        return '<div class="pv-modal__media pv-skeleton" style="min-height:340px;"></div>' +
               '<div class="pv-modal__content"><div class="pv-skeleton pv-skeleton--title mb-2"></div>' +
               '<div class="pv-skeleton pv-skeleton--text"></div>' +
               '<div class="pv-skeleton pv-skeleton--text" style="width:80%"></div>' +
               '<div class="pv-skeleton pv-skeleton--text" style="width:60%"></div>' +
               '<div class="pv-skeleton" style="height:48px;margin-top:16px;border-radius:14px;"></div></div>';
      case 'card':
        return '<div class="pv-product-card"><div class="pv-product-card__media pv-skeleton"></div>' +
               '<div class="pv-product-card__body">' +
                 '<div class="pv-skeleton pv-skeleton--text" style="width:40%"></div>' +
                 '<div class="pv-skeleton pv-skeleton--title"></div>' +
                 '<div class="pv-skeleton pv-skeleton--text" style="width:70%"></div>' +
                 '<div class="pv-skeleton" style="height:22px;width:80px;margin-top:8px;"></div>' +
               '</div></div>';
      case 'text':
        return '<div class="pv-skeleton pv-skeleton--text"></div>';
      case 'title':
        return '<div class="pv-skeleton pv-skeleton--title"></div>';
      default:
        return '<div class="pv-skeleton pv-skeleton--rect"></div>';
    }
  }

  /* =========================================================================
   * 11. Variant swatch selector
   * ========================================================================= */
  PV.swatches = function (root) {
    root = root || document;
    var groups = qsa('.pv-product-card__swatches, [data-pv-swatches]', root);
    groups.forEach(function (g) {
      if (g.__pvBound) return;
      g.__pvBound = true;
      var swatches = qsa('.pv-swatch', g);
      var input = qs('input[type="hidden"][data-pv-swatch-value]', g.closest('[data-pv-swatch-container]') || g.parentNode);
      swatches.forEach(function (sw) {
        on(sw, 'click', function () {
          swatches.forEach(function (x) { x.classList.remove('is-active'); x.setAttribute('aria-pressed', 'false'); });
          sw.classList.add('is-active');
          sw.setAttribute('aria-pressed', 'true');
          var val = sw.getAttribute('data-value') || sw.getAttribute('data-pv-value') || sw.title;
          if (input) { input.value = val; input.dispatchEvent(new Event('change', { bubbles: true })); }
          dispatch('swatch-selected', { swatch: sw, value: val, group: g });
        });
      });
    });
  };

  /* =========================================================================
   * 12. AJAX helper
   * ========================================================================= */
  PV.ajax = function (action, data) {
    return new Promise(function (resolve, reject) {
      if (!action) { reject(new Error('action required')); return; }
      var body = new URLSearchParams();
      body.append('action', action);
      body.append('nonce', PV.config.nonce);
      if (data && typeof data === 'object') {
        Object.keys(data).forEach(function (k) {
          var v = data[k];
          if (v == null) return;
          if (typeof v === 'object') v = JSON.stringify(v);
          body.append(k, v);
        });
      }
      fetch(PV.config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(resolve)
        .catch(function (err) {
          log('ajax error', action, err);
          reject(err);
        });
    });
  };

  /* =========================================================================
   * Global delegation: add-to-cart, quick-view, wishlist, swatches auto-init
   * ========================================================================= */
  on(document, 'click', function (e) {
    // AUDIT-FE-HOME-003 FIX: popular search chip — rellena el input del
    // header search form y lo envía a /tienda/. Antes los chips de búsquedas
    // populares ("Juegos de mesa", "Regalos", etc.) eran botones muertos.
    var chip = e.target.closest('[data-pv-search-chip]');
    if (chip) {
      e.preventDefault();
      var term = chip.getAttribute('data-pv-search-chip-value') || chip.getAttribute('data-pv-search-chip') || chip.textContent.trim();
      var input = document.querySelector('.pv-home-header__search-input, input[name="s"]');
      if (input) {
        input.value = term;
        var form = input.closest('form');
        if (form) {
          // Asegurar post_type=product (puede no estar si el form no es el del header).
          var pt = form.querySelector('input[name="post_type"]');
          if (!pt) {
            pt = document.createElement('input');
            pt.type = 'hidden';
            pt.name = 'post_type';
            pt.value = 'product';
            form.appendChild(pt);
          }
          form.submit();
        }
      }
      return;
    }
    // Quick view trigger
    var qv = e.target.closest('[data-pv-quickview]');
    if (qv) {
      e.preventDefault();
      var pid = qv.getAttribute('data-pv-quickview') || qv.getAttribute('data-product_id');
      PV.quickView(pid);
      return;
    }
    // AUDIT-FE-AP-001 FIX (Fase 1.5): wishlist toggle del card — persiste via
    // PV.ajax('ltms_pv_toggle_wishlist', ...). Antes este handler hacia solo
    // toggle visual (classList + aria-pressed) + dispatch del evento custom
    // `wishlist-toggle` que nadie escucha (verificado: ningun listener en
    // todo el design system para ese evento). El botón fav parecia funcionar
    // al usuario (corazon se llenaba) pero el favorito NUNCA se guardaba en
    // backend para guests (cookie ltms_wishlist) ni logged-in (tabla
    // bkr_lt_wishlists). Mismo patron que el bug AUDIT-FE-SF-006 de
    // follow-vendor (commit 43a2da5b) — ver LECCIONES_APRENDIDAS.md #137.
    //
    // Fix: invoca PV.ajax que manda PV.config.nonce (wp_create_nonce de
    // 'ltms_plaza_viva', ver class-ltms-native-templates.php:325-327). El
    // handler PHP LTMS_Wishlist::ajax_pv_toggle (registrado en init() de la
    // misma clase) valida contra ese nonce global y persiste via
    // LTMS_Wishlist::toggle() que ya soporta guest (cookie 30d) y logged-in
    // (DB bkr_lt_wishlists). El toggle visual queda optimista (UX
    // instantánea), pero en error se revierte para no engañar al usuario.
    var fav = e.target.closest('.pv-product-card__fav, [data-pv-fav]');
    if (fav) {
      e.preventDefault();
      var wasFavActive = fav.classList.contains('is-active');
      // Toggle optimista inmediato para UX instantánea.
      fav.classList.toggle('is-active');
      var active = fav.classList.contains('is-active');
      fav.setAttribute('aria-pressed', String(active));
      var productId = fav.getAttribute('data-product-id') || fav.getAttribute('data-pv-wishlist-toggle');
      if (!productId) return;
      PV.ajax('ltms_pv_toggle_wishlist', { product_id: productId })
        .then(function (res) {
          if (res && res.success) {
            var added = !!(res.data && res.data.added);
            // Reconciliar état visual con la respuesta authoritative del backend.
            if (added !== active) {
              if (added) { fav.classList.add('is-active'); } else { fav.classList.remove('is-active'); }
              fav.setAttribute('aria-pressed', String(added));
            }
            var msg = (res.data && res.data.message) || (added ? PV.i18n.added_to_wishlist : PV.i18n.removed_from_wishlist);
            if (added && fav.dataset.pvFav !== 'silent') PV.toast(msg, { type: 'success', duration: 1800 });
            dispatch('wishlist-toggle', { el: fav, active: added, productId: productId });
          } else {
            // Backend dijo no: revertir toggle optimista.
            if (wasFavActive) { fav.classList.add('is-active'); } else { fav.classList.remove('is-active'); }
            fav.setAttribute('aria-pressed', String(wasFavActive));
            PV.toast((res && res.data && res.data.message) || 'Error', { type: 'error' });
          }
        })
        .catch(function () {
          // Error de red/CSP: revertir toggle optimista.
          if (wasFavActive) { fav.classList.add('is-active'); } else { fav.classList.remove('is-active'); }
          fav.setAttribute('aria-pressed', String(wasFavActive));
          PV.toast('Error de conexión', { type: 'error' });
        });
      return;
    }
    // Custom ATC with fly animation
    var atc = e.target.closest('[data-pv-add-to-cart]:not(.ajax_add_to_cart)');
    if (atc) {
      e.preventDefault();
      var pid2 = atc.getAttribute('data-pv-add-to-cart');
      atc.classList.add('pv-btn--loading');
      PV.ajax('ltms_plaza_viva_add_to_cart', { product_id: pid2, quantity: 1 })
        .then(function (res) {
          atc.classList.remove('pv-btn--loading');
          if (res && res.success) {
            PV.flyToCart(atc, qs(PV.config.cartIconSelector) || atc);
            PV.Shopping.increment(res.data && res.data.count_delta || 1);
            PV.toast(PV.i18n.added_to_cart, { type: 'success', duration: 2200 });
          } else {
            PV.toast((res && res.data && res.data.message) || 'Error', { type: 'error' });
          }
        })
        .catch(function () {
          atc.classList.remove('pv-btn--loading');
          PV.toast('Error de conexión', { type: 'error' });
        });
    }

    // AUDIT-FE-SF-006 FIX (Fase 1.4): follow vendor — persiste el follow
    // via el nuevo endpoint ltms_follow_vendor. Antes el handler inline de
    // vendor-store.php solo cambiaba el label "Seguir"↔"Siguiendo" sin tocar
    // backend (follow cosmético). Ahora invoca PV.ajax que manda el nonce
    // global ltms_plaza_viva (nonce=PV.config.nonce) y el handler PHP
    // LTMS_Vendor_Followers::ajax_toggle_follow valida contra ese mismo
    // nonce (ver class-ltms-vendor-followers.php). El toggle visual state
    // permanece (UX instantánea) pero ahora también se persiste en
    // bkr_lt_vendor_followers; en caso de error se revierte el toggle visual
    // para evitar engañar al usuario.
    var followBtn = e.target.closest('[data-pv-follow-vendor]');
    if (followBtn) {
      e.preventDefault();
      var vendorId = followBtn.getAttribute('data-pv-follow-vendor');
      if (!vendorId) return;
      var wasActive = followBtn.getAttribute('aria-pressed') === 'true';
      var labelFollow = (PV.i18n && PV.i18n.followVendor) || 'Seguir';
      var labelFollowing = (PV.i18n && PV.i18n.followingVendor) || 'Siguiendo';
      // Toggle visual optimista.
      followBtn.setAttribute('aria-pressed', String(!wasActive));
      if (!wasActive) {
        followBtn.classList.add('is-following');
        followBtn.innerHTML = followBtn.innerHTML.replace(labelFollow, labelFollowing);
      } else {
        followBtn.classList.remove('is-following');
        followBtn.innerHTML = followBtn.innerHTML.replace(labelFollowing, labelFollow);
      }
      var countEl = document.querySelector('[data-pv-followers-count]');
      PV.ajax('ltms_follow_vendor', { vendor_id: vendorId })
        .then(function (res) {
          if (res && res.success && res.data) {
            if (countEl && typeof res.data.followers_count === 'number') {
              countEl.textContent = String(res.data.followers_count);
            }
          } else {
            // Error lógico: revertir toggle visual.
            followBtn.setAttribute('aria-pressed', String(wasActive));
            if (wasActive) {
              followBtn.classList.add('is-following');
              followBtn.innerHTML = followBtn.innerHTML.replace(labelFollow, labelFollowing);
            } else {
              followBtn.classList.remove('is-following');
              followBtn.innerHTML = followBtn.innerHTML.replace(labelFollowing, labelFollow);
            }
            PV.toast((res && res.data && res.data.message) || 'Error', { type: 'error' });
          }
        })
        .catch(function () {
          // Error de red: revertir toggle visual.
          followBtn.setAttribute('aria-pressed', String(wasActive));
          if (wasActive) {
            followBtn.classList.add('is-following');
            followBtn.innerHTML = followBtn.innerHTML.replace(labelFollow, labelFollowing);
          } else {
            followBtn.classList.remove('is-following');
            followBtn.innerHTML = followBtn.innerHTML.replace(labelFollowing, labelFollow);
          }
          PV.toast('Error de conexión', { type: 'error' });
        });
    }

    // AUDIT-FE-VS-JT-001 FIX (Fase 1.4 backlog closure): jump-to-tab desde el
    // botón "Ver políticas" del hero de vendor-store.php. Antes este handler
    // vivía en un bloque <script> inline en vendor-store.php:602-625, lo que
    // rompia CSP-compliance (la excepción restante tras AUDIT-FE-SF-006 que
    // ya migro el follow). Migrado aqui al listener global delegado con el
    // mismo patron closest('[data-pv-*]') de los demas handlers del design
    // system. Cierra 100% CSP-compliance en vendor-store.php (ya NO queda
    // ningun <script> inline en la plantilla).
    //
    // Behaviour: el boton [data-pv-jump-tab="X"] dispara un click programatico
    // en #pv-vendor-tab-X (tab del panel de politicas) y hace scroll suave al
    // contenedor #pv-vendor-panel-X. Si alguno no existe, no-op (no reviente).
    var jumpBtn = e.target.closest('[data-pv-jump-tab]');
    if (jumpBtn) {
      var jumpTarget = jumpBtn.getAttribute('data-pv-jump-tab');
      if (jumpTarget) {
        var tabEl = document.querySelector('#pv-vendor-tab-' + jumpTarget);
        if (tabEl) {
          e.preventDefault();
          tabEl.click();
          var panelEl = document.getElementById('pv-vendor-panel-' + jumpTarget);
          if (panelEl && typeof panelEl.scrollIntoView === 'function') {
            panelEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      }
    }
  });

  /* =========================================================================
   * v2.9.199 — Buy Now button injection
   * ========================================================================= */
  PV.injectBuyNow = function () {
    // Only on product pages
    var atcBtn = qs('form.cart .single_add_to_cart_button, .elementor-add-to-cart .single_add_to_cart_button');
    if (!atcBtn) return;
    // Don't double-inject
    if (qs('.ltms-buy-now-btn')) return;

    // Get product ID from the add-to-cart button or form
    var form = atcBtn.closest('form.cart');
    var pid = '';
    if (form) {
      var hidden = form.querySelector('input[name="add-to-cart"]');
      if (hidden) pid = hidden.value;
    }
    if (!pid && atcBtn.name === 'add-to-cart') pid = atcBtn.value;
    if (!pid) return;

    // Get checkout URL
    var checkoutUrl = (window.ltms_data && window.ltms_data.checkout_url) || '/checkout/';

    // Create Buy Now button
    var buyNow = document.createElement('a');
    buyNow.href = checkoutUrl + '?buy_now=' + encodeURIComponent(pid);
    buyNow.className = 'ltms-buy-now-btn';
    buyNow.setAttribute('aria-label', 'Comprar ahora');
    buyNow.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:6px"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round"/></svg>Comprar ahora';

    // Insert after the add-to-cart button
    if (atcBtn.parentNode) {
      atcBtn.parentNode.insertBefore(buyNow, atcBtn.nextSibling);
    }
  };

  /* =========================================================================
   * v2.9.200 — Homepage hero headline injection
   * ========================================================================= */
  PV.injectHeroHeadline = function () {
    // Only on homepage
    if (!document.body.classList.contains('home')) return;
    // Don't double-inject
    if (qs('.ltms-hero-headline')) return;

    // Find hero section — try multiple selectors for Elementor
    var hero = qs('.elementor-section') || qs('.e-con') || qs('.e-flex') || qs('[data-elementor-type]') || qs('main .elementor') || qs('.elementor-element');
    if (!hero) {
      // Fallback: inject at top of main content
      hero = qs('main') || qs('.site-main') || qs('#content') || qs('.page-content');
      if (!hero) return;
    }

    // Create headline
    var headline = document.createElement('div');
    headline.className = 'ltms-hero-headline';
    headline.innerHTML = '<h2 style="font-family:Albert Sans,sans-serif;font-size:clamp(24px,4vw,36px);font-weight:800;color:#fff;text-align:center;padding:12px 20px;background:linear-gradient(135deg,#E80001 0%,#B80001 100%);border-radius:14px;margin:0 auto;max-width:600px;box-shadow:0 4px 14px rgba(232,0,1,0.3);line-height:1.3;letter-spacing:-0.02em">Tu Marketplace de Confianza en Colombia 🇨🇴</h2><p style="text-align:center;color:#565C66;font-size:14px;margin-top:8px;font-weight:500">Miles de productos de vendedores verificados · PSE · Nequi · Envío a todo el país</p>';

    // Insert at the beginning of the hero section
    hero.insertBefore(headline, hero.firstChild);
  };

  /* =========================================================================
   * v2.9.200 — Shop page cleanup (remove duplicate search)
   * v2.9.214 — Hide social-share widgets (Facebook/WhatsApp/Email), improve
   *            price filter aesthetics, remove redundant 'Precio:' label.
   * ========================================================================= */
  PV.cleanShopPage = function () {
    // Only on shop/archive pages
    if (!document.body.classList.contains('archive') && !document.body.classList.contains('post-type-archive-product') && !document.body.classList.contains('tax-product_cat')) return;

    // Remove duplicate search bars in the sidebar/widget area
    var sidebarSearches = qsa('.widget-area .woocommerce-product-search, .sidebar .woocommerce-product-search, .widget_product_search');
    sidebarSearches.forEach(function (s) {
      var widget = s.closest('.widget');
      if (widget) widget.style.display = 'none';
      else s.style.display = 'none';
    });

    // v2.9.214: Hide social-share widgets on shop page sidebar.
    // These are theme/plugin widgets that show "Facebook / WhatsApp / Email"
    // share buttons — useful on product pages, NOT on shop sidebar where they
    // share the shop URL itself (low value, clutters the filter area).
    var socialSelectors = [
      '.widget_ltms_social_share', '.widget_ltms_social',
      '.widget[class*="social"]',
      '.widget-share', '.widget-social',
      '.widget .ltms-social-share',
      '.widget .share-buttons',
      '.widget_facebook', '.widget_whatsapp', '.widget_email_share',
      // Theme-specific social widgets
      '.widget[class*="facebook"]', '.widget[class*="whatsapp"]',
      '.widget a[href*="facebook.com/share"]', '.widget a[href*="wa.me"]',
      '.widget a[href*="mailto:"][class*="share"]'
    ];
    socialSelectors.forEach(function (sel) {
      qsa(sel).forEach(function (el) {
        var widget = el.closest('.widget') || el;
        widget.style.display = 'none';
      });
    });

    // v2.9.214: Also hide widgets whose visible TEXT starts with social labels.
    // This catches widgets without specific class names.
    qsa('.widget-area .widget, .sidebar .widget').forEach(function (widget) {
      if (widget.style.display === 'none') return;
      var text = (widget.textContent || '').trim().substring(0, 100).toLowerCase();
      // Match widgets that are PRIMARILY social share (not ones that mention
      // whatsapp in passing, like contact info).
      if (/^(facebook|whatsapp|email|compartir|share)\s*$/i.test(text) ||
          /^facebook\s*whatsapp\s*email/i.test(text.replace(/\s+/g, ' ')) ||
          /^facebook\s*\\\s*whatsapp/i.test(text)) {
        widget.style.display = 'none';
      }
    });

    // v2.9.214: Enhance price filter aesthetics + remove redundant 'Precio:' label
    var priceFilter = qs('.widget_price_filter, .price_filter, [class*=price-filter]');
    if (priceFilter) {
      var widget = priceFilter.closest('.widget');
      if (widget) {
        widget.style.background = '#fff';
        widget.style.padding = '16px';
        widget.style.borderRadius = '14px';
        widget.style.border = '1px solid #E7E5EC';
        widget.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
        widget.style.marginBottom = '16px';

        // Style the widget title (e.g., "Filtrar por precio")
        var title = widget.querySelector('.widget-title, h2, h3');
        if (title) {
          title.style.cssText = 'font-family:Albert Sans,sans-serif;font-size:14px;font-weight:700;color:#1A1F2E;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #E7E5EC;text-transform:uppercase;letter-spacing:0.04em';
        }

        // Style the price inputs (min/max)
        var inputs = widget.querySelectorAll('input[type="text"], input[type="number"], .price_slider_amount input');
        inputs.forEach(function (input) {
          if (input.id && input.id.indexOf('min_price') !== -1) {
            input.placeholder = 'Mín';
            input.style.cssText = 'width:80px !important;padding:8px 10px !important;border:1px solid #E7E5EC !important;border-radius:6px !important;font-size:13px !important;font-weight:600 !important;color:#1A1F2E !important;background:#fff !important;text-align:center';
          } else if (input.id && input.id.indexOf('max_price') !== -1) {
            input.placeholder = 'Máx';
            input.style.cssText = 'width:80px !important;padding:8px 10px !important;border:1px solid #E7E5EC !important;border-radius:6px !important;font-size:13px !important;font-weight:600 !important;color:#1A1F2E !important;background:#fff !important;text-align:center';
          }
        });

        // Hide redundant "Precio:" label (the inputs already show min/max)
        var priceLabel = widget.querySelector('.price_label, .price_slider_amount .price_label, label[for*="price"]');
        if (priceLabel && priceLabel.textContent.trim().length < 30) {
          priceLabel.style.display = 'none';
        }

        // Style the filter button
        var button = widget.querySelector('button, input[type="submit"], .button');
        if (button) {
          button.style.cssText = 'width:100%;margin-top:10px;padding:9px;border-radius:8px;background:#E80001;color:#fff;border:none;font-weight:600;font-size:13px;cursor:pointer;text-transform:uppercase;letter-spacing:0.04em';
        }

        // Style the slider track (if present)
        var slider = widget.querySelector('.price_slider, .ui-slider');
        if (slider) {
          slider.style.cssText = 'height:6px;border-radius:3px;background:#E7E5EC;margin:14px 0;border:none';
          var ranges = slider.querySelectorAll('.ui-slider-range');
          ranges.forEach(function (r) { r.style.background = '#E80001'; });
          var handles = slider.querySelectorAll('.ui-slider-handle');
          handles.forEach(function (h) {
            h.style.cssText = 'width:18px;height:18px;border-radius:50%;background:#fff;border:2px solid #E80001;cursor:pointer;top:-7px';
          });
        }
      }
    }

    // v2.9.214: Style other widgets in the sidebar for consistency
    qsa('.widget-area .widget, .sidebar .widget').forEach(function (widget) {
      if (widget.style.display === 'none') return;
      // Skip price filter (already styled above)
      if (widget.classList.contains('widget_price_filter')) return;

      // Apply consistent card styling
      if (!widget.dataset.ltmsStyled) {
        widget.dataset.ltmsStyled = '1';
        // Only style if it doesn't already have prominent styling
        var currentBg = widget.style.background || getComputedStyle(widget).background;
        if (!currentBg || currentBg === 'rgba(0, 0, 0, 0)' || currentBg === 'transparent') {
          widget.style.background = '#fff';
          widget.style.padding = '16px';
          widget.style.borderRadius = '14px';
          widget.style.border = '1px solid #E7E5EC';
          widget.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
          widget.style.marginBottom = '16px';
        }

        // Style widget titles consistently
        var title = widget.querySelector('.widget-title, h2, h3');
        if (title && !title.dataset.ltmsStyled) {
          title.dataset.ltmsStyled = '1';
          title.style.cssText = 'font-family:Albert Sans,sans-serif;font-size:14px;font-weight:700;color:#1A1F2E;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #E7E5EC;text-transform:uppercase;letter-spacing:0.04em';
        }
      }
    });
  };

  /* =========================================================================
   * v2.9.200 — Price display enhancement
   * v2.9.211 — Add dedup guard (was running twice due to native-templates
   *            inline script redefining PV.enhancePriceDisplay).
   * ========================================================================= */
  PV.enhancePriceDisplay = function () {
    // Only on product pages
    if (!document.body.classList.contains('single-product')) return;

    // v2.9.211: Dedup guard — check both class and data attribute to prevent
    // double-injection when both plaza-viva.js and native-templates inline
    // script run their init.
    if (document.querySelector('.ltms-price-shipping-info, [data-ltms-shipping-info="1"]')) return;

    var price = qs('.single-product .price, .pv-product-page .price, .product .price');
    if (!price) return;

    // Add shipping info below price
    var shippingInfo = document.createElement('div');
    shippingInfo.className = 'ltms-price-shipping-info';
    shippingInfo.setAttribute('data-ltms-shipping-info', '1');
    shippingInfo.style.cssText = 'font-size:13px;color:#0BA37F;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px';
    shippingInfo.innerHTML = '<span>🚚</span> <span>Envío gratis incluido</span>';

    // Insert after price
    if (price.parentNode) {
      price.parentNode.insertBefore(shippingInfo, price.nextSibling);
    }
  };

  /* =========================================================================
   * Auto-init on DOM ready
   * ========================================================================= */
  function autoInit() {
    PV.Shopping.init();
    qsa('[data-pv-tabs]').forEach(PV.tabs);
    qsa('[data-pv-accordion]').forEach(PV.accordion);
    qsa('.pv-accordion').forEach(PV.accordion);
    PV.qtyStepper(document);
    PV.swatches(document);
    // Auto countdowns
    qsa('[data-pv-countdown]').forEach(function (el) {
      var secs = parseInt(el.getAttribute('data-pv-countdown'), 10);
      if (secs > 0) PV.countdown(el, secs);
    });
    // Auto sticky ATC
    var stickyBar = qs('.pv-sticky-atc');
    var stickySentinel = qs('[data-pv-sticky-sentinel]');
    if (stickyBar && stickySentinel) PV.stickyATC({ bar: stickyBar, sentinel: stickySentinel });

    // v2.9.200 — Inject "Buy Now" button next to Add to Cart on product pages.
    PV.injectBuyNow();

    // v2.9.200 — Homepage hero headline injection.
    // Delay 500ms to allow Elementor to render its containers.
    setTimeout(function() { PV.injectHeroHeadline(); }, 500);

    // v2.9.200 — Shop duplicate search removal.
    setTimeout(function() { PV.cleanShopPage(); }, 500);

    // v2.9.200 — Price prominence enhancement.
    PV.enhancePriceDisplay();

    dispatch('ready', { version: PV.version });
  }

  if (document.readyState === 'loading') {
    on(document, 'DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

})(window, document);

    // ─────────────────────────────────────────────────────────────
    // SF-00 v2: Inyectar vendor, rating, favoritos en cards de Elementor
    // ─────────────────────────────────────────────────────────────
    function enhanceElementorCards() {
        var cards = document.querySelectorAll('li.product:not(.pv-enhanced)');
        if (!cards.length) return;

        cards.forEach(function(card) {
            card.classList.add('pv-enhanced');
            var productId = card.className.match(/post-(\d+)/);
            productId = productId ? parseInt(productId[1], 10) : 0;
            if (!productId) return;

            var titleEl = card.querySelector('.woocommerce-loop-product__title');
            var linkEl = card.querySelector('.woocommerce-loop-product__link');
            if (!titleEl) return;

            // 1. Inyectar rating si no existe
            if (!card.querySelector('.star-rating, .pv-card-rating')) {
                var ratingEl = document.createElement('div');
                ratingEl.className = 'pv-card-rating';
                ratingEl.style.cssText = 'display:flex;align-items:center;gap:4px;margin-top:4px;font-size:12px;color:#f59e0b;';
                ratingEl.innerHTML = '<span style="color:#f59e0b;">★★★★★</span><span style="color:#9ca3af;font-size:11px;">(0)</span>';
                titleEl.parentElement.insertBefore(ratingEl, titleEl.nextSibling);
            }

            // 2. Inyectar vendor name si no existe
            if (!card.querySelector('.pv-card-vendor')) {
                var vendorEl = document.createElement('div');
                vendorEl.className = 'pv-card-vendor';
                vendorEl.style.cssText = 'font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.03em;margin-top:6px;';
                vendorEl.textContent = 'Tienda Lo Tengo';
                titleEl.parentElement.insertBefore(vendorEl, titleEl);
            }

            // 3. Inyectar botón favoritos si no existe
            if (!card.querySelector('.pv-card-fav')) {
                var favEl = document.createElement('button');
                favEl.className = 'pv-card-fav';
                favEl.style.cssText = 'position:absolute;top:8px;right:8px;background:rgba(255,255,255,0.9);border:none;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:all 0.2s;';
                favEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
                favEl.setAttribute('aria-label', 'Añadir a favoritos');
                favEl.setAttribute('data-pv-wishlist-toggle', productId);
                favEl.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var svg = this.querySelector('svg');
                    if (svg.getAttribute('fill') === 'none') {
                        svg.setAttribute('fill', '#ef4444');
                        svg.setAttribute('stroke', '#ef4444');
                    } else {
                        svg.setAttribute('fill', 'none');
                        svg.setAttribute('stroke', '#6b7280');
                    }
                });
                // Asegurar que el card tiene position:relative
                card.style.position = 'relative';
                card.appendChild(favEl);
            }
        });
    }

    // Ejecutar después de que Elementor renderice
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceElementorCards);
    } else {
        enhanceElementorCards();
    }
    // Re-ejecutar después de 2s (Elementor a veces renderiza tarde)
    setTimeout(enhanceElementorCards, 2000);
    // Re-ejecutar en scroll (lazy load de Elementor)
    var enhanceTimer;
    window.addEventListener('scroll', function() {
        clearTimeout(enhanceTimer);
        enhanceTimer = setTimeout(enhanceElementorCards, 300);
    }, { passive: true });

  /* =========================================================================
   * AUDIT-FE-CART-001 FIX (Fase 1.6): scope CART — migración del bloque
   * <script> inline de cart.php:962-1029 al design system global. Cierra
   * CSP-compliance para cart.php (la excepción restante significativa en
   * el design system, paridad con vendor-store.php ya 100% CSP-compliant
   * tras AUDIT-FE-VS-JT-001).
   *
   * Incluye 4 behaviours (3 migrados del inline original + 1 nuevo del
   * AUDIT-FE-CART-009):
   *   1. Quantity stepper: botones +/- actualizan el input + dispatch 'change'.
   *   2. Coupon inline: sincroniza input visible con form WC oculto + Enter.
   *   3. Update cart highlight: marca el botón 'Actualizar carrito' como
   *      is-pending cuando cambian cantidades (UX: indica necesario submit).
   *   4. Empty cart handler (AUDIT-FE-CART-009): botón data-pv-empty-cart
   *      invoca PV.ajax('ltms_pv_empty_cart', ...) con confirmación nativa
   *      del browser antes de vaciar. Tras success hace redirect a la
   *      empty-cart view (URL retornada por el handler).
   * ========================================================================= */
  (function cartScope() {
    function initCart() {
      var scope = document.querySelector('.pv-scope.pv-cart');
      if (!scope) return;
      // Skip si el scope es la empty-cart view (no hay items ni coupon).
      if (scope.querySelector('.pv-cart__empty-card')) return;

      /* --- 1. Quantity stepper (botones +/- actualizan el input) -------- */
      var qtyWraps = Array.prototype.slice.call(scope.querySelectorAll('.pv-cart__item-qty'));
      qtyWraps.forEach(function (wrap) {
        var input = wrap.querySelector('.qty');
        var minus = wrap.querySelector('.pv-qty__btn--minus');
        var plus  = wrap.querySelector('.pv-qty__btn--plus');
        var min   = parseInt(wrap.getAttribute('data-pv-qty-min') || (input && input.min) || 1, 10);
        var max   = parseInt(wrap.getAttribute('data-pv-qty-max') || (input && input.max) || 99, 10);
        if (!input) return;
        if (isNaN(min) || min < 1) min = 1;
        if (isNaN(max) || max < 1) max = 99;
        if (minus) {
          minus.addEventListener('click', function () {
            var v = parseInt(input.value || 0, 10);
            if (isNaN(v)) v = min;
            v = Math.max(min, v - 1);
            input.value = v;
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
        }
        if (plus) {
          plus.addEventListener('click', function () {
            var v = parseInt(input.value || 0, 10);
            if (isNaN(v)) v = min;
            v = Math.min(max, v + 1);
            input.value = v;
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
        }
      });

      /* --- 2. Coupon inline — sincroniza input visible con form WC ------ */
      var couponInput = scope.querySelector('#pv-cart-coupon-code');
      var couponForm  = scope.querySelector('#pv-cart-coupon-form');
      var couponBtn   = scope.querySelector('[name="apply_coupon"]');
      if (couponInput && couponForm && couponBtn) {
        couponBtn.addEventListener('click', function (e) {
          var hidden = couponForm.querySelector('input[name="coupon_code"]');
          if (hidden) { hidden.value = couponInput.value; }
          // Re-dirigimos el submit al form nativo de WC para que aplique.
          e.preventDefault();
          couponForm.submit();
        });
        // Permitir Enter para aplicar.
        couponInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            couponBtn.click();
          }
        });
      }

      /* --- 3. Update cart highlight (cuando cambian cantidades) ---------- */
      var qtyInputs = Array.prototype.slice.call(scope.querySelectorAll('.pv-cart__item-qty .qty'));
      var updateBtn = scope.querySelector('.pv-cart__update-btn');
      if (qtyInputs.length && updateBtn) {
        qtyInputs.forEach(function (input) {
          input.addEventListener('change', function () {
            updateBtn.classList.add('is-pending');
          });
        });
      }

      /* --- 4. AUDIT-FE-CART-009 FIX: empty cart con confirmación + AJAX - */
      var emptyBtn = scope.querySelector('[data-pv-empty-cart]');
      if (emptyBtn) {
        emptyBtn.addEventListener('click', function () {
          // Confirmación obligatoria antes de vaciar: click accidental no
          // tiene undo en WC nativo. Usar confirm() nativo del browser
          // (CSP-compliant, no inline JS, accesible via teclado).
          var msg = '¿Vaciar todo el carrito? Esta acción no se puede deshacer.';
          if (typeof PV.i18n === 'object' && PV.i18n.empty_cart_confirm) {
            msg = PV.i18n.empty_cart_confirm;
          }
          if (!window.confirm(msg)) return;

          // Estado loading: deshabilitar botón + indicador visual.
          var originalLabel = emptyBtn.innerHTML;
          emptyBtn.disabled = true;
          emptyBtn.classList.add('is-loading');
          emptyBtn.innerHTML = '<span>· · ·</span>';

          PV.ajax('ltms_pv_empty_cart', {})
            .then(function (res) {
              if (res && res.success) {
                if (PV.toast) {
                  var successMsg = (res.data && res.data.message) || PV.i18n.empty_cart_done || 'Carrito vaciado';
                  PV.toast(successMsg, { type: 'success', duration: 1800 });
                }
                // Tras success, redirect a la URL que retorna el handler
                // (debería ser wc_get_cart_url() que WC muestra como empty-cart view).
                var redirect = (res.data && res.data.redirect) || (window.ltms_data && window.ltms_data.cart_url) || '/cart/';
                // Pequeño delay para que el toast sea visible antes del redirect.
                setTimeout(function () { window.location.href = redirect; }, 400);
              } else {
                if (PV.toast) {
                  PV.toast((res && res.data && res.data.message) || 'Error', { type: 'error' });
                }
                emptyBtn.disabled = false;
                emptyBtn.classList.remove('is-loading');
                emptyBtn.innerHTML = originalLabel;
              }
            })
            .catch(function () {
              if (PV.toast) PV.toast('Error de conexión', { type: 'error' });
              emptyBtn.disabled = false;
              emptyBtn.classList.remove('is-loading');
              emptyBtn.innerHTML = originalLabel;
            });
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initCart);
    } else {
      initCart();
    }
  })();

  /* =========================================================================
   * AUDIT-FE-CKO-003 FIX (Fase 1.7): scope CHECKOUT — migración del bloque
   * <script> inline de checkout.php:622-916 (~295 líneas, el bloque inline
   * más grande del design system) al archivo global. Cierra CSP-compliance
   * para checkout.php (paridad con cart.php Fase 1.6 y vendor-store.php).
   *
   * Incluye 7 behaviours migrados del inline original:
   *   1. Stepper sync: marca el paso activo del header según IntersectionObserver.
   *   2. Shipping radio cards: marca .is-selected al cambiar.
   *   3. Payment radio cards: muestra/oculta fields del gateway seleccionado.
   *   4. ship_to_different_address toggle: muestra/oculta shipping fields.
   *   5. Submit loading state: deshabilita el botón mientras se procesa.
   *   6. WOOCCM label override: corrige labels (Departamento, Municipio, etc.)
   *      que WOOCCM reconstruye desde su BD después de los filtros PHP.
   *   7. sync billing_state from billing_city: extrae departamento del option
   *      "Municipio — Departamento" y auto-puebla billing_state para WC shipping.
   *
   * AUDIT-FE-CKO-004 FIX (Fase 1.7): el valor ltmsCountry antes era inyectado
   * por PHP via <?php echo esc_js()?> dentro del script inline (rompía CSP al
   * ser un valor dinámico inyectado). Ahora se lee de PV.config.country
   * (expuesto por wp_localize_script en class-ltms-native-templates.php:329).
   * ========================================================================= */
  (function checkoutScope() {
    function initCheckout() {
      var scope = document.querySelector('.pv-scope.pv-checkout');
      if (!scope) return;

      /* --- 1. Stepper sync (marcar paso activo según scroll/visibility) - */
      var stepBlocks = Array.prototype.slice.call(scope.querySelectorAll('[data-step-block]'));
      var stepperItems = Array.prototype.slice.call(scope.querySelectorAll('.pv-checkout__stepper-step[data-step]'));

      function markActiveStep(stepNum) {
        stepperItems.forEach(function (item) {
          var n = parseInt(item.getAttribute('data-step'), 10);
          item.classList.toggle('is-active', n === stepNum);
          item.classList.toggle('is-done', n < stepNum);
        });
      }

      if (stepBlocks.length && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              var n = parseInt(entry.target.getAttribute('data-step-block'), 10);
              markActiveStep(n);
            }
          });
        }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });
        stepBlocks.forEach(function (b) { io.observe(b); });
      }

      /* --- 2. Shipping radio cards (cambiar .is-selected) --------------- */
      var shipRadios = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-shipping-radio]'));
      shipRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
          var wrap = radio.closest('.pv-shipping-options');
          if (!wrap) return;
          Array.prototype.slice.call(wrap.querySelectorAll('.pv-shipping-option')).forEach(function (li) {
            li.classList.remove('is-selected');
          });
          radio.closest('.pv-shipping-option').classList.add('is-selected');
        });
      });

      /* --- 3. Payment radio cards (mostrar/ocultar fields) -------------- */
      var payRadios = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-payment-radio]'));
      var payFields = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-payment-fields]'));

      function updatePaymentSelection() {
        payRadios.forEach(function (radio) {
          var li = radio.closest('.pv-payment-option');
          if (!li) return;
          var isSelected = radio.checked;
          li.classList.toggle('is-selected', isSelected);
          var id = li.getAttribute('data-pv-payment-option');
          payFields.forEach(function (field) {
            var fid = field.getAttribute('data-pv-payment-fields');
            if (fid === id) {
              field.hidden = !isSelected;
            }
          });
        });
      }
      payRadios.forEach(function (radio) {
        radio.addEventListener('change', updatePaymentSelection);
      });
      updatePaymentSelection();

      /* --- 4. ship_to_different_address toggle (mostrar shipping fields) - */
      var shipToggle = scope.querySelector('#ship_to_different_address');
      var shipFieldsWrap = scope.querySelector('.woocommerce-shipping-fields');
      if (shipToggle && shipFieldsWrap) {
        function syncShipFields() {
          if (shipToggle.checked) {
            shipFieldsWrap.classList.add('shipping-fields--visible');
            shipFieldsWrap.style.display = 'block';
          } else {
            shipFieldsWrap.classList.remove('shipping-fields--visible');
            shipFieldsWrap.style.display = 'none';
          }
        }
        shipToggle.addEventListener('change', syncShipFields);
        syncShipFields();
      }

      /* --- 5. Submit loading state ------------------------------------- */
      var submitBtn = scope.querySelector('.pv-checkout__submit');
      var checkoutForm = scope.querySelector('form.woocommerce-checkout');
      if (submitBtn && checkoutForm) {
        checkoutForm.addEventListener('submit', function () {
          // Validación mínima: validar campos required visibles.
          submitBtn.classList.add('is-loading');
          submitBtn.setAttribute('disabled', 'disabled');
        });
      }

      /* --- 6. Fix field labels (bypass WOOCCM) ------------------------- */
      /* WOOCCM (WooCommerce Checkout Manager) reconstruye los labels desde
       * su propia BD DESPUÉS de los filtros woocommerce_billing_fields y
       * woocommerce_form_field. La única forma de override confiable es
       * modificar el DOM via JS después de que WOOCCM termina.
       *
       * AUDIT-FE-CKO-004 FIX: el país se lee de PV.config.country (expuesto
       * por wp_localize_script). Antes este valor era inyectado por PHP en
       * el <script> inline via <?php echo esc_js(LTMS_Core_Config::get_country())?>
       * lo que rompía la migración a un archivo JS externo.
       */
      var ltmsCountry = PV.config.country || 'CO';
      var labelMap = {};
      if (ltmsCountry === 'CO') {
        labelMap = {
          'billing_state': 'Departamento',
          'shipping_state': 'Departamento',
          'billing_city': 'Municipio',
          'shipping_city': 'Municipio',
          'billing_country': 'País',
          'shipping_country': 'País',
          'billing_postcode': 'Código postal',
          'shipping_postcode': 'Código postal',
          'billing_address_1': 'Dirección',
          'shipping_address_1': 'Dirección',
          'billing_address_2': 'Apartamento, suite, etc. (opcional)',
          'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
      } else if (ltmsCountry === 'MX') {
        labelMap = {
          'billing_state': 'Estado',
          'shipping_state': 'Estado',
          'billing_city': 'Municipio / Alcaldía',
          'shipping_city': 'Municipio / Alcaldía',
          'billing_country': 'País',
          'shipping_country': 'País',
          'billing_postcode': 'Código postal',
          'shipping_postcode': 'Código postal',
          'billing_address_1': 'Dirección',
          'shipping_address_1': 'Dirección',
          'billing_address_2': 'Apartamento, suite, etc. (opcional)',
          'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
      }

      function fixFieldLabels() {
        Object.keys(labelMap).forEach(function (fieldKey) {
          var newLabel = labelMap[fieldKey];
          // Buscar el label por 'for' attribute.
          var labelEl = scope.querySelector('label[for="' + fieldKey + '"]');
          if (!labelEl) return;
          // Preservar el <abbr class="required"> o <span class="optional"> si existe.
          var abbr = labelEl.querySelector('abbr.required, abbr');
          var optionalSpan = labelEl.querySelector('span.optional, .optional');
          // Reconstruir el label.
          labelEl.innerHTML = '';
          labelEl.appendChild(document.createTextNode(newLabel));
          if (abbr) {
            labelEl.appendChild(document.createTextNode(' '));
            labelEl.appendChild(abbr);
          }
          if (optionalSpan) {
            labelEl.appendChild(document.createTextNode(' '));
            labelEl.appendChild(optionalSpan);
          }
        });
        // Ocultar campos duplicados: billing_phone y billing_email en step 2
        // (ya están en step 1). FIX #10 (CHECKOUT-AUDIT): contamos cuántos
        // <label for="..."> existen para el mismo target (en vez de inspeccionar
        // el texto del label — que este mismo filter reescribe).
        var phoneEmailKeys = ['billing_phone', 'shipping_phone', 'billing_email', 'shipping_email'];
        var occurrenceCount = {};
        phoneEmailKeys.forEach(function (k) { occurrenceCount[k] = 0; });
        phoneEmailKeys.forEach(function (fieldKey) {
          scope.querySelectorAll('label[for="' + fieldKey + '"]').forEach(function (lbl) {
            occurrenceCount[fieldKey]++;
            if (occurrenceCount[fieldKey] < 2) return;
            var fieldId = fieldKey + '_field';
            var field = scope.querySelector('#' + fieldId);
            if (field) field.style.display = 'none';
          });
        });

        // Auto-seleccionar país: CO o MX según configuración.
        var countrySelect = scope.querySelector('#billing_country, #shipping_country');
        if (countrySelect && countrySelect.value !== ltmsCountry) {
          countrySelect.value = ltmsCountry;
          countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }

      // Ejecutar inmediatamente y después de un delay (WOOCCM JS que corre tarde).
      fixFieldLabels();
      setTimeout(fixFieldLabels, 500);
      setTimeout(fixFieldLabels, 1500);
      // También observar mutaciones del DOM (WOOCCM puede modificar dinámicamente).
      if ('MutationObserver' in window) {
        var observer = new MutationObserver(function (mutations) {
          var shouldFix = false;
          mutations.forEach(function (m) {
            if (m.type === 'childList' || m.type === 'characterData') {
              shouldFix = true;
            }
          });
          if (shouldFix) {
            fixFieldLabels();
          }
        });
        observer.observe(scope, { childList: true, subtree: true, characterData: true });
        // Dejar de observar después de 5 segundos (no matar el performance).
        setTimeout(function () { observer.disconnect(); }, 5000);
      }

      /* --- 7. Sync billing_state from billing_city (DANE municipio) ---- */
      /* El select billing_city usa códigos DANE (5 dígitos) donde los primeros
       * 2 = departamento. Cuando el usuario selecciona un municipio, auto-poblamos
       * billing_state con el departamento correspondiente para que WC pueda
       * calcular el envío (WC requiere billing_state). Estrategia: extraer el
       * nombre del departamento del texto del option ("Municipio — Departamento")
       * y buscarlo en las opciones de billing_state por coincidencia de texto.
       */
      function syncStateFromCity() {
        var citySelect = scope.querySelector('#billing_city, #ltms-municipality-select');
        var stateSelect = scope.querySelector('#billing_state');
        if (!citySelect || !stateSelect) return;
        if (!citySelect.value || citySelect.value.length < 2) return;

        var selectedOption = citySelect.options[citySelect.selectedIndex];
        if (!selectedOption) return;
        var optionText = selectedOption.textContent || '';
        var deptName = '';
        var dashIdx = optionText.indexOf('—');
        if (dashIdx !== -1) {
          deptName = optionText.substring(dashIdx + 1).trim();
        } else if (optionText.indexOf('-') !== -1) {
          deptName = optionText.substring(optionText.indexOf('-') + 1).trim();
        }
        if (!deptName) return;

        var bestMatch = null;
        var bestScore = 0;
        for (var i = 0; i < stateSelect.options.length; i++) {
          var opt = stateSelect.options[i];
          var optText = (opt.textContent || '').trim();
          var normOpt = optText.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
          var normDept = deptName.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
          if (normOpt === normDept) {
            bestMatch = opt;
            bestScore = 100;
            break;
          }
          if (normOpt.indexOf(normDept) !== -1 || normDept.indexOf(normOpt) !== -1) {
            var score = Math.min(normOpt.length, normDept.length);
            if (score > bestScore) {
              bestScore = score;
              bestMatch = opt;
            }
          }
        }

        if (bestMatch && bestScore > 0) {
          stateSelect.value = bestMatch.value;
          stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
          if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('update_checkout');
          }
        }
      }

      var citySelectForSync = scope.querySelector('#billing_city, #ltms-municipality-select');
      if (citySelectForSync) {
        citySelectForSync.addEventListener('change', function () {
          setTimeout(syncStateFromCity, 100);
        });
        setTimeout(syncStateFromCity, 800);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initCheckout);
    } else {
      initCheckout();
    }
  })();

  /* =========================================================================
   * AUDIT-FE-HC FIX (Fase 1.9): scope HELP — migración del bloque <script>
   * inline de help-center.php:344-418 al design system global.
   *
   * Cierra CSP-compliance para help-center.php (paridad con cart.php Fase 1.6,
   * checkout.php Fase 1.7 y vendor-store.php). 3 hallazgos resueltos:
   *
   *   * AUDIT-FE-HC-002 (P1, CSP-compliance + script-tag inline): el bloque
   *     de líneas 344-418 (~74 líneas) migrado aquí. 2 behaviours migrados:
   *       1. FAQ search: filtrado en vivo por texto (vanilla JS).
   *       2. Chat trigger: abre Tawk.to o Intercom si está configurado,
   *          carga el widget bajo demanda si aún no está cargado.
   *
   *   * AUDIT-FE-HC-003 (P0, alert() en fallback prohibido por design system):
   *     Línea 414 original usaba `alert('<?php echo esc_js(...)?>')` como
   *     fallback si PV.toast no existía. El QA_REPORT.md documenta `alert(): 0`
   *     como regla del design system (todos los mensajes via toast system).
   *     Fix: si PV.toast no existe, no hacemos nada (no se viola la regla
   *     con un alert() no-estándar). El fallback a alert() rompía la regla
   *     pero nunca se ejecutaba en producción (PV.toast siempre disponible
   *     en páginas con design system).
   *
   *   * AUDIT-FE-HC-004 (P0, script-tag inline para chat provider setup):
   *     Líneas 117-123 generaban `<script>window.__ltmsTawkProperty=...;</script>`
   *     inline en PHP para exponer el ID de Tawk/Intercom al JS. Fix: el
   *     botón data-pv-chat-trigger="tawk" YA tiene data-pv-chat-tawk="ID"
   *     attribute (esc_attr en el HTML), el JS lee de ahí — no necesita
   *     window.__ltms* inyectado via script inline. Elimina la necesidad
   *     del segundo bloque script-tag inline (el setup HTML).
   *
   *   * AUDIT-FE-HC-005 (P1, PHP inline injection dentro del JS): las
   *     inyecciones `<?php echo esc_js(...)?>` dentro del JS inline (líneas
   *     367, 412, 414 originales) eran imposibles de migrar a un archivo
   *     JS externo (valor dinámico PHP dentro del JS). Fix: strings via
   *     PV.i18n (faq_result_singular/plural, chat_unavailable) que ya
   *     están expuestos por wp_localize_script.
   *
   *   * AUDIT-FE-HC-001 (P1, onsubmit="return false;" inline event handler):
   *     Línea 150 original tenía `<form ... onsubmit="return false;">` que
   *     es un inline event handler que rompe CSP-compliance del HTML. Fix:
   *     el handler JS ahora previene el default del submit (no necesita
   *     el atributo inline).
   * ========================================================================= */
  (function helpScope() {
    function initHelp() {
      var scope = document.querySelector('.pv-scope.pv-help');
      if (!scope) return;

      /* --- 1. FAQ search — filtrado en vivo por texto (vanilla JS) ------ */
      var search = scope.querySelector('[data-pv-faq-search]');
      var items  = scope.querySelectorAll('[data-pv-faq-item]');
      var empty  = scope.querySelector('#pv-help-faq-empty');
      var count  = scope.querySelector('#pv-help-faq-count');
      if (!search || !items.length) return;

      // AUDIT-FE-HC-001 FIX: el form tenía onsubmit="return false;" inline.
      // Ahora prevenimos el default via JS (CSP-compliant — sin inline handler).
      var searchForm = search.closest('form');
      if (searchForm) {
        searchForm.addEventListener('submit', function (e) { e.preventDefault(); });
      }

      function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

      search.addEventListener('input', function () {
        var q = norm(search.value.trim());
        var visible = 0;
        items.forEach(function (it) {
          var text = norm(it.textContent || '');
          var match = !q || text.indexOf(q) !== -1;
          it.style.display = match ? '' : 'none';
          if (match) visible++;
        });
        if (count) {
          // AUDIT-FE-HC-005 FIX: strings via PV.i18n (no más inyección PHP en JS).
          var singular = (PV.i18n && PV.i18n.faq_result_singular) || 'resultado';
          var plural  = (PV.i18n && PV.i18n.faq_result_plural) || 'resultados';
          count.textContent = visible + ' ' + (visible === 1 ? singular : plural);
        }
        if (empty) {
          empty.classList.toggle('pv-hidden', visible > 0);
        }
      });

      /* --- 2. Chat trigger — abre Tawk.to o Intercom si está configurado - */
      scope.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pv-chat-trigger]');
        if (!btn) return;
        e.preventDefault();
        var provider = btn.getAttribute('data-pv-chat-trigger');

        // AUDIT-FE-HC-004 FIX: el ID del provider se lee del data-attribute
        // del botón (ya estaba en el HTML original), en vez de leer
        // window.__ltmsTawkProperty que era inyectado por un script-tag
        // inline separado en PHP (help-center.php:120/122).
        var tawkId    = btn.getAttribute('data-pv-chat-tawk') || '';
        var intercomId = btn.getAttribute('data-pv-chat-intercom') || '';

        if (provider === 'tawk' && typeof window.Tawk_API !== 'undefined' && window.Tawk_API.toggle) {
          window.Tawk_API.toggle();
          return;
        }
        if (provider === 'intercom' && typeof window.Intercom !== 'undefined') {
          window.Intercom('show');
          return;
        }
        // Fallback: si el widget aún no cargó, lo cargamos bajo demanda.
        if (provider === 'tawk' && tawkId) {
          var s1 = document.createElement('script');
          s1.async = true; s1.src = 'https://embed.tawk.to/' + tawkId + '/default';
          s1.charset = 'UTF-8';
          s1.setAttribute('crossorigin', '*');
          document.body.appendChild(s1);
          s1.onload = function () {
            setTimeout(function () {
              if (window.Tawk_API && window.Tawk_API.toggle) window.Tawk_API.toggle();
            }, 600);
          };
          return;
        }
        if (provider === 'intercom' && intercomId) {
          (function () {
            var w = window;
            var ic = w.Intercom;
            if (typeof ic === 'function') { ic('reattach_activator'); ic('update', w.intercomSettings); }
            else {
              var d = document;
              var i = function () { i.c(arguments); };
              i.q = []; i.c = function (args) { i.q.push(args); };
              w.Intercom = i;
              var l = function () {
                var s = d.createElement('script');
                s.type = 'text/javascript'; s.async = true;
                s.src = 'https://widget.intercom.io/widget/' + intercomId;
                var x = d.getElementsByTagName('script')[0];
                x.parentNode.insertBefore(s, x);
              };
              if (w.attachEvent) { w.attachEvent('onload', l); }
              else { w.addEventListener('load', l, false); }
            }
          })();
          setTimeout(function () { if (window.Intercom) window.Intercom('show'); }, 600);
          return;
        }
        // AUDIT-FE-HC-003 FIX: el fallback original usaba alert() cuando
        // PV.toast no existía — alert() está PROHIBIDO por el design system
        // (QA_REPORT.md: alert(): 0). En producción PV.toast siempre está
        // disponible en páginas con design system. Si por algún motivo no
        // lo está, registramos via console.warn (no alert).
        if (PV.toast) {
          var msg = (PV.i18n && PV.i18n.chat_unavailable) || 'El chat no está disponible en este momento.';
          PV.toast(msg, { type: 'warning', duration: 3000 });
        } else if (window.console && window.console.warn) {
          window.console.warn('[PV] Chat no disponible — PV.toast no cargado.');
        }
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initHelp);
    } else {
      initHelp();
    }
  })();

  /* =========================================================================
   * AUDIT-FE-HOME-001 FIX (Fase 1.10): scope HOME — migración del bloque
   * <script> inline de home.php:1009-1052 al design system global. Cierra
   * CSP-compliance para home.php (la última excepción significativa junto
   * con single-product.php — SP-001 a continuación).
   *
   * Análisis del bloque inline original (2 behaviours):
   *   1. Chips de búsqueda: registraba un click listener en
   *      [data-pv-search-chip] para rellenar el input + focus + (mobile)
   *      scrollIntoView. PERO este handler YA existía en el delegado global
   *      `on(document, 'click', ...)` líneas 588-614 (AUDIT-FE-HOME-003
   *      FIX, commit 9882789b) que rellena + hace `form.submit()`. Como el
   *      handler global se registró primero y dispara navegación SÍNCRONA,
   *      el listener inline (que se registraba después al cargar el footer)
   *      NUNCA tenía oportunidad de correr visible — era código muerto
   *      duplicado que rompía CSP sin aportar nada. NO se migra.
   *   2. Header sticky shadow: toggle `.is-scrolled` en `.pv-home-header`
   *      según `window.scrollY > 8`. PERO la clase `.is-scrolled` NO está
   *      definida en ningún CSS (verificado: grep en ltms-plaza-viva.css,
   *      ltms-homepage-fixes.css, ltms-frontend.css = 0 matches). El
   *      behaviour era cosmético sin efecto. NO se migra (UI muerta —
   *      mismo patrón que LECCIONES #139, OT-002: leer/escribir datos que
   *      nadie usa). Si en el futuro se quiere añadir sombra al header al
   *      hacer scroll, será un clase CSS nueva + este scope — pero sólo
   *      cuando exista CSS que la consuma (no antes).
   * ========================================================================= */
  (function homeScope() {
    function initHome() {
      var scope = document.querySelector('.pv-scope.pv-home');
      if (!scope) return;
      // No hay behaviours a (re)inicializar en este momento — el handler
      // global AUDIT-FE-HOME-003 cubre los chips, y la clase is-scrolled
      // no tiene CSS. Mantenemos el IIFE como válvula de extensión para
      // futuros behaviours específicos de la home (ver comentario arriba).
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initHome);
    } else {
      initHome();
    }
  })();

  /* =========================================================================
   * AUDIT-FE-SP-001 FIX (Fase 1.10): scope PRODUCT — migración del bloque
   * <script> inline de single-product.php:890-1048 al design system global.
   * Cierra CSP-compliance para single-product.php (3.0.0 sufría la última
   * excepción CSP significativa del design system, junto con home.php).
   *
   * Behaviours migrados (5):
   *   1. Sticky nav: resaltar enlace activo via IntersectionObserver +
   *      smooth scroll al click (sin navegar — previene default y usa
   *      scrollIntoView). Preserva el histórico via history.replaceState.
   *   2. Bundle recompute: recalcula total al toggle checkboxes. Aplica
   *      descuento si hay >= 2 items seleccionados. Updatea totalEl color
   *      (var(--accent)) y saveEl hidden según corresponda.
   *   3. Bundle add-to-cart: ANTES reimplementaba `fetch` directo con
   *      URLSearchParams + action + nonce a mano. AHORA usa PV.ajax que
   *      ya envuelve el nonce global 'ltms_plaza_viva' (paridad con todos
   *      los add-to-cart del design system — AUDIT-FE-PV-001 Fase 1.4).
   *   4. Toast de éxito tras bundle add-to-cart: usa PV.toast con i18n
   *      'addedToCart' (de wp_localize_script) o fallback.
   *   5. Shopping.refresh tras bundle add-to-cart: refresca el contador
   *      del carrito del design system (ya existía en el inline original).
   *
   * AUDIT-FE-SP-002 FIX (Fase 1.10): el config de moneda WC para formatear
   * el total del bundle en JS (currency symbol, decimal, thousand, position)
   * y el `bundle_discount` (entero de % descuento) eran inyectados inline en
   * el JS del template via PHP echo dentro del script-tag. Ahora se exponen
   * via wp_localize_script('ltms_plaza_viva', 'ltms_data', ...) en
   * class-ltms-native-templates.php como `ltms_data.pv_currency` y el
   * descuento se lee del data-attr `data-pv-bundle-discount` del contenedor
   * `.pv-bundle` (evita PHP dentro del JS). El currency config ya no necesita
   * inline script — JS lee `PV.config.pvCurrency` (mapeo en el init de
   * PV.config al inicio de este archivo).
   * ========================================================================= */
  (function productScope() {
    function initProduct() {
      var scope = document.querySelector('.pv-scope.pv-product-page');
      if (!scope) return;

      /* --- 1. Sticky nav: resaltar enlace de sección activa --------------- */
      var navLinks = Array.prototype.slice.call(scope.querySelectorAll('.pv-sticky-nav__link'));
      var sections = [];
      navLinks.forEach(function (link) {
        var hash = link.getAttribute('href') || '';
        if (hash.charAt(0) === '#') {
          var sec = document.getElementById(hash.slice(1));
          if (sec) sections.push({ link: link, el: sec });
        }
      });

      function setActive(id) {
        navLinks.forEach(function (l) { l.classList.remove('is-active'); l.removeAttribute('aria-current'); });
        var match = sections.filter(function (s) { return s.el.id === id; })[0];
        if (match) { match.link.classList.add('is-active'); match.link.setAttribute('aria-current', 'true'); }
      }

      if ('IntersectionObserver' in window && sections.length) {
        var io = new IntersectionObserver(function (entries) {
          entries.forEach(function (e) { if (e.isIntersecting) setActive(e.target.id); });
        }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
        sections.forEach(function (s) { io.observe(s.el); });
      }

      // Smooth scroll al click en los enlaces de ancla.
      navLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
          var hash = link.getAttribute('href') || '';
          if (hash.charAt(0) !== '#') return;
          var target = document.getElementById(hash.slice(1));
          if (!target) return;
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          if (history.replaceState) history.replaceState(null, '', hash);
          setActive(target.id);
        });
      });

      /* --- 2. Bundle: total dinámico ------------------------------------- */
      var bundle = scope.querySelector('.pv-bundle');
      if (!bundle) return;

      var items = Array.prototype.slice.call(bundle.querySelectorAll('[data-pv-bundle-item]'));
      var totalEl = bundle.querySelector('[data-pv-bundle-total]');
      var saveEl  = bundle.querySelector('[data-pv-bundle-save]');
      var addBtn  = bundle.querySelector('[data-pv-bundle-add]');
      var discountPct = parseInt(bundle.getAttribute('data-pv-bundle-discount') || '0', 10);
      if (isNaN(discountPct) || discountPct < 0) discountPct = 0;

      // AUDIT-FE-SP-002 FIX: formato de moneda leído de PV.config.pvCurrency
      // (expuesto via wp_localize_script en class-ltms-native-templates.php),
      // no desde un global inline inyectado dentro del script-tag del template.
      function formatMoney(n) {
        var cfg = (PV.config && PV.config.pvCurrency) || {};
        var dec = (typeof cfg.decimals === 'number') ? cfg.decimals : 2;
        var dsep = cfg.decimal || '.';
        var tsep = cfg.thousand || ',';
        var sym  = cfg.symbol || '$';
        var pos  = cfg.position || 'left';
        var neg = n < 0;
        n = Math.abs(n);
        var fixed = n.toFixed(dec);
        var parts = fixed.split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, tsep);
        var numStr = (parts[1] && dec > 0) ? (intPart + dsep + parts[1]) : intPart;
        var out;
        switch (pos) {
          case 'right':        out = numStr + sym; break;
          case 'left_space':   out = sym + ' ' + numStr; break;
          case 'right_space':  out = numStr + ' ' + sym; break;
          case 'left':
          default:             out = sym + numStr; break;
        }
        return neg ? ('-' + out) : out;
      }

      function selectedItems() {
        return items.filter(function (it) {
          var cb = it.querySelector('.pv-bundle__check');
          return cb ? cb.checked : false;
        });
      }

      function recompute() {
        var sel = selectedItems();
        var sum = sel.reduce(function (acc, it) { return acc + (parseFloat(it.getAttribute('data-pv-bundle-price')) || 0); }, 0);
        var applyDiscount = sel.length >= 2;
        var save = applyDiscount ? sum * (discountPct / 100) : 0;
        var total = sum - save;
        if (totalEl) {
          totalEl.textContent = formatMoney(total);
          totalEl.style.color = applyDiscount ? 'var(--accent)' : '';
        }
        if (saveEl) {
          if (applyDiscount && save > 0) {
            saveEl.hidden = false;
            saveEl.textContent = '- ' + formatMoney(save);
          } else {
            saveEl.hidden = true;
          }
        }
        if (addBtn) addBtn.disabled = sel.length === 0;
      }

      items.forEach(function (it) {
        var cb = it.querySelector('.pv-bundle__check');
        if (!cb) return;
        it.classList.toggle('is-selected', cb.checked);
        cb.addEventListener('change', function () {
          it.classList.toggle('is-selected', cb.checked);
          recompute();
        });
      });
      recompute();

      /* --- 3. Bundle add-to-cart (AJAX secuencial via PV.ajax) ----------- */
      if (addBtn) {
        addBtn.addEventListener('click', function () {
          var sel = selectedItems();
          if (!sel.length) return;
          addBtn.classList.add('pv-btn--loading');
          addBtn.disabled = true;

          // AUDIT-FE-SP-001 FIX: cada item del bundle se add-to-cartea via
          // PV.ajax (no fetch manual con nonce/action). PV.ajax manda el
          // nonce global 'ltms_plaza_viva' automáticamente (paridad con
          // el resto del design system — AUDIT-FE-PV-001 Fase 1.4 garantea
          // que el handler PHP valida contra ese nonce).
          var queue = sel.slice();
          function next() {
            if (!queue.length) {
              addBtn.classList.remove('pv-btn--loading');
              addBtn.disabled = false;
              if (PV.toast) { PV.toast((PV.i18n && PV.i18n.addedToCart) || 'Añadido al carrito', { type: 'success' }); }
              if (PV.Shopping) { try { PV.Shopping.refresh(); } catch (_) { /* noop */ } }
              return;
            }
            var it = queue.shift();
            var pid = it.getAttribute('data-pv-bundle-id');
            PV.ajax('ltms_plaza_viva_add_to_cart', { product_id: pid, quantity: '1' })
              .then(function () { next(); })
              .catch(function () { next(); }); // continuar la cola en error
          }
          next();
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initProduct);
    } else {
      initProduct();
    }
  })();
