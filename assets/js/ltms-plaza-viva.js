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
    ended: 'Oferta finalizada'
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
    // Quick view trigger
    var qv = e.target.closest('[data-pv-quickview]');
    if (qv) {
      e.preventDefault();
      var pid = qv.getAttribute('data-pv-quickview') || qv.getAttribute('data-product_id');
      PV.quickView(pid);
      return;
    }
    // Wishlist toggle
    var fav = e.target.closest('.pv-product-card__fav, [data-pv-fav]');
    if (fav) {
      e.preventDefault();
      fav.classList.toggle('is-active');
      var active = fav.classList.contains('is-active');
      fav.setAttribute('aria-pressed', String(active));
      if (active && fav.dataset.pvFav !== 'silent') PV.toast(PV.i18n.added_to_wishlist, { type: 'success', duration: 1800 });
      dispatch('wishlist-toggle', { el: fav, active: active, productId: fav.getAttribute('data-product-id') });
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

    // Enhance price filter visibility
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
      }
    }
  };

  /* =========================================================================
   * v2.9.200 — Price display enhancement
   * ========================================================================= */
  PV.enhancePriceDisplay = function () {
    // Only on product pages
    if (!document.body.classList.contains('single-product')) return;

    var price = qs('.single-product .price, .pv-product-page .price, .product .price');
    if (!price) return;

    // Add shipping info below price
    var shippingInfo = document.createElement('div');
    shippingInfo.className = 'ltms-price-shipping-info';
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
