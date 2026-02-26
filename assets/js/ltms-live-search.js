/**
 * LTMS Live Search JS
 * Búsqueda en tiempo real con debounce para el panel del vendedor y el marketplace.
 * Soporta búsqueda de productos, pedidos y vendedores con resaltado de términos.
 * Version: 1.5.0
 */

'use strict';

window.LTMS = window.LTMS || {};

LTMS.LiveSearch = (function ($) {

    // ── Instances ──────────────────────────────────────────────────
    const instances = {};

    // ── Default config ─────────────────────────────────────────────
    const DEFAULTS = {
        ajaxUrl:      '/wp-admin/admin-ajax.php',
        nonce:        '',
        action:       'ltms_live_search',
        debounceMs:   300,
        minChars:     2,
        maxResults:   8,
        searchType:   'products',    // 'products' | 'orders' | 'vendors' | 'global'
        renderItem:   null,          // Custom renderer function
        onSelect:     null,          // Callback on item selection
        placeholder:  'Buscar...',
        noResultsMsg: 'Sin resultados para',
        loadingMsg:   'Buscando...',
        highlight:    true,
        cacheResults: true,
    };

    // ── Cache ──────────────────────────────────────────────────────
    const cache = {};

    // ── Create Instance ─────────────────────────────────────────────

    /**
     * Inicializa una instancia de live search en un elemento.
     *
     * @param  {string|HTMLElement|jQuery} selector - Input de búsqueda.
     * @param  {Object}                   options   - Opciones de configuración.
     * @return {Object}                             - Instancia pública.
     */
    function create(selector, options) {
        const cfg = Object.assign({}, DEFAULTS, options || {});
        const $input = $(selector);

        if (!$input.length) return null;

        const instanceId = 'ltms-ls-' + Date.now();
        $input.attr('data-ltms-ls', instanceId);
        $input.attr('placeholder', cfg.placeholder);
        $input.attr('autocomplete', 'off');

        // Create dropdown container
        const $dropdown = $('<div>', {
            class: 'ltms-ls-dropdown',
            id:    instanceId + '-dropdown',
            role:  'listbox',
        }).insertAfter($input);

        const $wrap = $input.wrap('<div class="ltms-ls-wrap"></div>').parent();
        $wrap.append($dropdown);

        let debounceTimer  = null;
        let currentRequest = null;
        let activeIndex    = -1;
        let currentResults = [];

        // ── Event Bindings ─────────────────────────────────────────

        $input.on('input.ltmsSls', function () {
            const query = $input.val().trim();

            clearTimeout(debounceTimer);
            resetActiveIndex();

            if (query.length < cfg.minChars) {
                closeDropdown();
                return;
            }

            debounceTimer = setTimeout(function () {
                doSearch(query);
            }, cfg.debounceMs);
        });

        $input.on('keydown.ltmsSls', function (e) {
            const $items = $dropdown.find('.ltms-ls-item');
            if (!$items.length) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, $items.length - 1);
                    updateActiveItem($items);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    updateActiveItem($items);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (activeIndex >= 0) {
                        $items.eq(activeIndex).trigger('click');
                    }
                    break;
                case 'Escape':
                    closeDropdown();
                    $input.blur();
                    break;
            }
        });

        $input.on('focus.ltmsSls', function () {
            const query = $input.val().trim();
            if (query.length >= cfg.minChars && currentResults.length > 0) {
                $dropdown.show();
            }
        });

        $(document).on('click.ltmsSls' + instanceId, function (e) {
            if (!$wrap.is(e.target) && !$wrap.has(e.target).length) {
                closeDropdown();
            }
        });

        // ── Search ─────────────────────────────────────────────────

        function doSearch(query) {
            const cacheKey = cfg.action + ':' + cfg.searchType + ':' + query;

            if (cfg.cacheResults && cache[cacheKey]) {
                renderResults(cache[cacheKey], query);
                return;
            }

            // Show loading
            $dropdown.html(`<div class="ltms-ls-loading">${cfg.loadingMsg}</div>`).show();

            if (currentRequest) {
                currentRequest.abort();
            }

            currentRequest = $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                data: {
                    action: cfg.action,
                    nonce:  cfg.nonce,
                    q:      query,
                    type:   cfg.searchType,
                    limit:  cfg.maxResults,
                },
            })
            .done(function (res) {
                if (res.success) {
                    const results = res.data.results || [];
                    if (cfg.cacheResults) {
                        cache[cacheKey] = results;
                    }
                    currentResults = results;
                    renderResults(results, query);
                } else {
                    showNoResults(query);
                }
            })
            .fail(function (xhr) {
                if (xhr.statusText !== 'abort') {
                    showError();
                }
            });
        }

        // ── Rendering ──────────────────────────────────────────────

        function renderResults(results, query) {
            $dropdown.empty();
            resetActiveIndex();

            if (!results.length) {
                showNoResults(query);
                return;
            }

            results.forEach(function (item, idx) {
                const $item = $('<div>', {
                    class: 'ltms-ls-item',
                    role:  'option',
                    'data-index': idx,
                });

                if (typeof cfg.renderItem === 'function') {
                    $item.html(cfg.renderItem(item, query));
                } else {
                    $item.html(defaultRenderItem(item, query, cfg.searchType));
                }

                $item.on('click', function () {
                    selectItem(item, $item);
                });

                $dropdown.append($item);
            });

            $dropdown.show();
        }

        /**
         * Renderer por defecto según tipo de búsqueda.
         *
         * @param  {Object} item
         * @param  {string} query
         * @param  {string} type
         * @return {string}
         */
        function defaultRenderItem(item, query, type) {
            switch (type) {
                case 'products':
                    return renderProductItem(item, query);
                case 'orders':
                    return renderOrderItem(item, query);
                case 'vendors':
                    return renderVendorItem(item, query);
                default:
                    return renderGlobalItem(item, query);
            }
        }

        function renderProductItem(item, query) {
            const name  = highlight(item.name, query);
            const price = item.price_html || ('$' + item.price);
            const img   = item.image_url || '';
            return `
                <div class="ltms-ls-product">
                    ${img ? `<img class="ltms-ls-thumb" src="${escapeAttr(img)}" alt="">` : '<div class="ltms-ls-thumb-placeholder"></div>'}
                    <div class="ltms-ls-product-info">
                        <span class="ltms-ls-product-name">${name}</span>
                        <span class="ltms-ls-product-price">${price}</span>
                        ${item.sku ? `<span class="ltms-ls-product-sku">SKU: ${escapeHtml(item.sku)}</span>` : ''}
                    </div>
                    ${item.stock_status === 'outofstock' ? '<span class="ltms-ls-oos">Sin stock</span>' : ''}
                </div>`;
        }

        function renderOrderItem(item, query) {
            const num = highlight('#' + item.number, query);
            return `
                <div class="ltms-ls-order">
                    <span class="ltms-ls-order-num">${num}</span>
                    <span class="ltms-ls-order-customer">${escapeHtml(item.customer_name || '')}</span>
                    <span class="ltms-ls-order-total">${escapeHtml(item.total || '')}</span>
                    <span class="ltms-ls-order-status ltms-kds-status-${escapeAttr(item.status)}">${escapeHtml(item.status_label || item.status)}</span>
                </div>`;
        }

        function renderVendorItem(item, query) {
            const name = highlight(item.store_name || item.display_name, query);
            return `
                <div class="ltms-ls-vendor">
                    ${item.avatar ? `<img class="ltms-ls-avatar" src="${escapeAttr(item.avatar)}" alt="">` : '<div class="ltms-ls-avatar-placeholder"></div>'}
                    <div class="ltms-ls-vendor-info">
                        <span class="ltms-ls-vendor-name">${name}</span>
                        <span class="ltms-ls-vendor-plan">${escapeHtml(item.plan || '')}</span>
                    </div>
                </div>`;
        }

        function renderGlobalItem(item, query) {
            return `
                <div class="ltms-ls-global-item">
                    <span class="ltms-ls-global-icon">${escapeHtml(item.icon || '🔍')}</span>
                    <div>
                        <span class="ltms-ls-global-title">${highlight(item.title, query)}</span>
                        ${item.subtitle ? `<span class="ltms-ls-global-sub">${escapeHtml(item.subtitle)}</span>` : ''}
                    </div>
                    <span class="ltms-ls-global-type">${escapeHtml(item.type || '')}</span>
                </div>`;
        }

        function showNoResults(query) {
            $dropdown.html(`<div class="ltms-ls-no-results">${cfg.noResultsMsg} "<strong>${escapeHtml(query)}</strong>"</div>`).show();
        }

        function showError() {
            $dropdown.html('<div class="ltms-ls-error">Error al buscar. Intenta de nuevo.</div>').show();
        }

        // ── Selection ──────────────────────────────────────────────

        function selectItem(item, $item) {
            $input.val(item.name || item.title || item.number || '');
            closeDropdown();

            if (typeof cfg.onSelect === 'function') {
                cfg.onSelect(item, $input);
            } else {
                // Default: navigate to item URL if available
                if (item.url) {
                    window.location.href = item.url;
                } else if (item.edit_url) {
                    window.location.href = item.edit_url;
                }
            }
        }

        // ── Navigation ─────────────────────────────────────────────

        function updateActiveItem($items) {
            $items.removeClass('ltms-ls-item-active');
            if (activeIndex >= 0) {
                $items.eq(activeIndex).addClass('ltms-ls-item-active');
            }
        }

        function resetActiveIndex() {
            activeIndex = -1;
        }

        function closeDropdown() {
            $dropdown.hide().empty();
            currentResults = [];
            resetActiveIndex();
        }

        // ── Helpers ────────────────────────────────────────────────

        function highlight(text, query) {
            if (!cfg.highlight || !text || !query) return escapeHtml(String(text || ''));
            const safe  = escapeHtml(String(text));
            const safeQ = escapeHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return safe.replace(new RegExp(`(${safeQ})`, 'gi'), '<mark class="ltms-ls-highlight">$1</mark>');
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
        }

        function escapeAttr(str) {
            return String(str).replace(/"/g, '&quot;');
        }

        // ── Public Instance API ─────────────────────────────────────

        const instance = {
            destroy() {
                $input.off('.ltmsSls');
                $(document).off('click.ltmsSls' + instanceId);
                $dropdown.remove();
                $input.unwrap();
                delete instances[instanceId];
            },
            clearCache() {
                Object.keys(cache).forEach(k => delete cache[k]);
            },
            setSearchType(type) { cfg.searchType = type; },
        };

        instances[instanceId] = instance;
        return instance;
    }

    // ── Auto-init from data attributes ────────────────────────────

    function autoInit() {
        $('[data-ltms-search]').each(function () {
            const $el = $(this);
            create($el, {
                action:     $el.data('ltms-search-action') || 'ltms_live_search',
                searchType: $el.data('ltms-search-type')   || 'products',
                nonce:      $el.data('ltms-nonce')          || (typeof ltmsDashboard !== 'undefined' ? ltmsDashboard.nonce : ''),
                ajaxUrl:    $el.data('ltms-ajax-url')       || '/wp-admin/admin-ajax.php',
            });
        });
    }

    // ── Public API ──────────────────────────────────────────────────

    return { create, autoInit };

})(jQuery);

jQuery(function () {
    LTMS.LiveSearch.autoInit();
});
