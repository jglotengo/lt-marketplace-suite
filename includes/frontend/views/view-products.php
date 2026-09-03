<?php
/**
 * Vista SPA: Productos del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$vendor_id = get_current_user_id();
// PROD-LIST-PAGING FIX: antes la vista usaba wc_get_products(['limit'=>50])
// SIN paginación ni búsqueda → con catálogos grandes (Kosmetic: 1,826
// productos VTEX) el vendedor solo veía los 50 más recientes y el resto era
// inaccesible. Ahora el grid se puebla vía AJAX (ltms_get_products_data) con
// paginación server-side de 24 y búsqueda. El contador total y el paginador
// los renderiza el JS (#ltms-products-list).
$products_total = (int) wc_get_products( [
    'author'   => $vendor_id,
    'limit'    => 1,
    'paginate' => true,
    'status'   => [ 'publish', 'draft', 'pending' ],
] )->total ?? 0;
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Productos', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-products-back-btn" title="Volver al inicio">
                ← <?php esc_html_e( 'Volver', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn">
                ➕ <?php esc_html_e( 'Nuevo Producto', 'ltms' ); ?>
            </button>
        </div>
    </div>

    <?php if ( $products_total === 0 ) : ?>
    <div class="ltms-empty-state">
        <div class="ltms-empty-icon">🛍️</div>
        <h3><?php esc_html_e( 'Aún no tienes productos', 'ltms' ); ?></h3>
        <p><?php esc_html_e( 'Agrega tu primer producto para comenzar a vender.', 'ltms' ); ?></p>
        <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn-empty">
            <?php esc_html_e( 'Agregar Producto', 'ltms' ); ?>
        </button>
    </div>
    <?php else : ?>

    <!-- PROD-LIST-PAGING FIX: buscador + grid AJAX paginado + contador -->
    <div class="ltms-products-toolbar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
        <input type="search" id="ltms-products-search" class="ltms-input" style="flex:1;min-width:220px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;"
               placeholder="<?php esc_attr_e( 'Buscar producto por nombre...', 'ltms' ); ?>"
               aria-label="<?php esc_attr_e( 'Buscar producto', 'ltms' ); ?>">
        <span class="ltms-products-count" id="ltms-products-count" style="font-size:0.85rem;color:#6b7280;white-space:nowrap;">
            <?php echo esc_html( sprintf( __( '%d productos', 'ltms' ), $products_total ) ); ?>
        </span>
    </div>

    <!-- Grid de productos (se rellena por AJAX con paginación 24) -->
    <div class="ltms-products-grid" id="ltms-products-grid">
        <div style="grid-column:1/-1;text-align:center;color:#6b7280;padding:40px 0;" id="ltms-products-loading">
            <?php esc_html_e( 'Cargando productos...', 'ltms' ); ?>
        </div>
    </div>

    <!-- Paginación -->
    <nav class="ltms-products-pagination" id="ltms-products-pagination" aria-label="<?php esc_attr_e( 'Paginación de productos', 'ltms' ); ?>"></nav>

    <script>
    (function () {
        var grid     = document.getElementById('ltms-products-grid');
        var pager    = document.getElementById('ltms-products-pagination');
        var countEl  = document.getElementById('ltms-products-count');
        var searchEl = document.getElementById('ltms-products-search');
        var perPage  = 24;
        var current  = 1;
        var totalPages = 1;
        var debounce;

        function renderCard(p) {
            var statusLabel = p.status === 'publish' ? 'Publicado' : 'Borrador';
            var statusClass = p.status === 'publish' ? 'ltms-badge-success' : 'ltms-badge-warning';
            var img = p.image
                ? '<img src="' + p.image + '" alt="' + p.name.replace(/"/g, '&quot;') + '" loading="lazy">'
                : '<span style="font-size:2rem;color:#d1d5db;">📷</span>';
            var tipoMap = { physical: '📦 Físico', digital: '💾 Digital', service: '🔧 Servicio', booking: '🏨 Turismo' };
            var tipo = tipoMap[p.product_type] || '📦 Físico';
            return '' +
                '<div class="ltms-product-card">' +
                '  <div class="ltms-product-img">' + img + '</div>' +
                '  <div class="ltms-product-body">' +
                '    <div class="ltms-product-name">' + p.name + '</div>' +
                '    <div class="ltms-product-price">' + (p.price ? '$' + Number(p.price).toLocaleString('es-CO') : '—') + '</div>' +
                '    <div style="margin-top:6px;">' +
                '      <span class="ltms-badge ' + statusClass + '" style="font-size:0.7rem;">' + statusLabel + '</span>' +
                '      <span class="ltms-badge ltms-badge-info" style="font-size:0.7rem;margin-left:4px;background:#e0f2fe;color:#0369a1;">' + tipo + '</span>' +
                '    </div>' +
                '  </div>' +
                '  <div class="ltms-product-actions">' +
                '    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-edit-product-btn" data-product-id="' + p.id + '">✏️ Editar</button>' +
                '    <a href="' + p.edit_url + '" class="ltms-btn ltms-btn-outline ltms-btn-sm" target="_blank">👁 Ver</a>' +
                '    <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-delete-product-btn" data-product-id="' + p.id + '" data-product-name="' + p.name.replace(/"/g, '&quot;') + '">🗑 Eliminar</button>' +
                '  </div>' +
                '</div>';
        }

        function renderPager() {
            if (totalPages <= 1) { pager.innerHTML = ''; return; }
            var html = '<button type="button" class="ltms-pg-btn" data-pg="' + (current - 1) + '" ' + (current <= 1 ? 'disabled' : '') + '>‹ Anterior</button>';
            for (var i = 1; i <= totalPages; i++) {
                if (totalPages > 15 && i > 1 && i < totalPages && Math.abs(i - current) > 2) {
                    if (html.indexOf('…') === -1) html += '<span class="ltms-pg-ellipsis">…</span>';
                    continue;
                }
                html += '<button type="button" class="ltms-pg-btn' + (i === current ? ' is-active' : '') + '" data-pg="' + i + '">' + i + '</button>';
            }
            html += '<button type="button" class="ltms-pg-btn" data-pg="' + (current + 1) + '" ' + (current >= totalPages ? 'disabled' : '') + '>Siguiente ›</button>';
            pager.innerHTML = html;
        }

        function load(page) {
            current = page;
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#6b7280;padding:40px 0;">Cargando...</div>';
            var body = new URLSearchParams();
            body.append('action', 'ltms_get_products_data');
            body.append('nonce', typeof ltmsDashboard !== 'undefined' ? ltmsDashboard.nonce : '');
            body.append('page', page);
            body.append('per_page', perPage);
            if (searchEl.value.trim()) body.append('search', searchEl.value.trim());
            fetch(typeof ltmsDashboard !== 'undefined' ? ltmsDashboard.ajax_url : '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp.success) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#dc2626;padding:40px 0;">Error al cargar productos.</div>'; return; }
                var d = resp.data;
                totalPages = d.total_pages || 1;
                if (!d.products.length) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#6b7280;padding:40px 0;">Sin resultados.</div>'; }
                else { grid.innerHTML = d.products.map(renderCard).join(''); }
                if (countEl) countEl.textContent = d.total + ' productos';
                renderPager();
                // Bindear acciones de los modales (editar/eliminar) del JS existente.
                if (window.jQuery) {
                    jQuery(grid).find('.ltms-edit-product-btn').off('click.ltmsprods');
                    jQuery(grid).find('.ltms-delete-product-btn').off('click.ltmsprods');
                }
            }).catch(function () { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#dc2626;padding:40px 0;">Error de red.</div>'; });
        }

        pager.addEventListener('click', function (e) {
            var btn = e.target.closest('.ltms-pg-btn');
            if (!btn || btn.disabled) return;
            var pg = parseInt(btn.getAttribute('data-pg'), 10);
            if (pg >= 1 && pg <= totalPages) load(pg);
        });

        searchEl.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () { load(1); }, 350);
        });

        load(1);
    })();
    </script>

    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: Nuevo Producto  (id="ltms-modal-new-product")
     Requerido por los botones data-ltms-modal-open="ltms-modal-new-product"
     ═══════════════════════════════════════════════════════════════ -->
<div class="ltms-modal" id="ltms-modal-new-product" role="dialog" aria-modal="true" aria-labelledby="ltms-np-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:560px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;max-height:90vh;overflow-y:auto;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 id="ltms-np-title" style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Nuevo Producto', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>">✕</button>
        </div>

        <div id="ltms-np-notice" class="ltms-modal-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"></div>

        <!-- Imagen del producto -->
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Imagen del Producto', 'ltms' ); ?></label>
            <div id="ltms-np-img-preview" style="width:100%;height:140px;border:2px dashed #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#f9fafb;margin-bottom:8px;overflow:hidden;">
                <span style="color:#9ca3af;font-size:2rem;">📷</span>
            </div>
            <input type="file" id="ltms-np-img-input" accept="image/*" style="display:none;">
            <input type="hidden" id="ltms-np-image-id" value="">
            <button type="button" style="padding:6px 14px;border:1.5px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;" id="ltms-np-img-btn">
                📁 <?php esc_html_e( 'Seleccionar imagen', 'ltms' ); ?>
            </button>
            <span id="ltms-np-img-status" style="font-size:0.8rem;color:#6b7280;margin-left:8px;"></span>
        </div>

        <!-- v2.9.88 P1: Gallery upload (multiple images) -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Galería (imágenes adicionales)', 'ltms' ); ?></label>
            <div id="ltms-np-gallery-preview" style="display:flex;gap:8px;flex-wrap:wrap;min-height:60px;padding:8px;border:2px dashed #e5e7eb;border-radius:8px;background:#f9fafb;align-items:center;">
                <span style="color:#d1d5db;font-size:0.8rem;"><?php esc_html_e( 'Click para añadir imágenes', 'ltms' ); ?></span>
            </div>
            <input type="file" id="ltms-np-gallery-input" accept="image/*" multiple style="display:none;">
            <input type="hidden" id="ltms-np-gallery-ids" value="">
            <button type="button" style="padding:6px 14px;border:1.5px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;margin-top:6px;" id="ltms-np-gallery-btn">
                📁 <?php esc_html_e( 'Añadir imágenes', 'ltms' ); ?>
            </button>
            <span style="font-size:0.75rem;color:#9ca3af;margin-left:8px;"><?php esc_html_e( 'Máx 5 imágenes. JPG, PNG, WEBP.', 'ltms' ); ?></span>
        </div>

        <!-- Nombre -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Nombre del Producto *', 'ltms' ); ?></label>
            <input type="text" id="ltms-np-name" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" required placeholder="<?php esc_attr_e( 'Ej: Camiseta azul talla M', 'ltms' ); ?>">
        </div>

        <!-- Descripción -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción', 'ltms' ); ?></label>
            <textarea id="ltms-np-desc" rows="3" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;" placeholder="<?php esc_attr_e( 'Describe tu producto...', 'ltms' ); ?>"></textarea>
        </div>

        <!-- PROD-09: Descripción corta (excerpt) — aparece en la página de producto como short description -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción Corta', 'ltms' ); ?></label>
            <textarea id="ltms-np-short-desc" rows="2" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;" placeholder="<?php esc_attr_e( 'Resumen breve que aparece junto al precio (máx 200 caracteres)...', 'ltms' ); ?>"></textarea>
        </div>

        <!-- Precio, Precio de oferta y Stock en fila -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Precio *', 'ltms' ); ?></label>
                <input type="number" id="ltms-np-price" min="0" step="0.01" required style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="0.00">
            </div>
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Oferta', 'ltms' ); ?></label>
                <input type="number" id="ltms-np-sale-price" min="0" step="0.01" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>">
            </div>
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Stock', 'ltms' ); ?></label>
                <input type="number" id="ltms-np-stock" min="0" step="1" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Dejar vacío = ilimitado', 'ltms' ); ?>">
            </div>
        </div>

        <!-- CS-07 + PROD-01: Tipo — grilla 2×3 con los 5 tipos (agregado restaurant) -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                <?php esc_html_e( 'Tipo de Producto', 'ltms' ); ?>
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-physical-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-physical" value="physical" checked style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">📦 <?php esc_html_e( 'Físico', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-digital-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-digital" value="digital" style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">💾 <?php esc_html_e( 'Digital', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-service-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-service" value="service" style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">🔧 <?php esc_html_e( 'Servicio', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-booking-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-booking" value="booking" style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">🏨 <?php esc_html_e( 'Turismo', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-restaurant-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-restaurant" value="restaurant" style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">🍽️ <?php esc_html_e( 'Restaurante', 'ltms' ); ?></span>
                </label>
                <?php // AUDIT-PROD-044: añadido el 6º tipo 'variable' — paridad con loadNewProductView (eliminado). ?>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-variable-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-variable" value="variable" style="accent-color:#1a5276;">
                    <span style="font-size:0.8rem;">🎨 <?php esc_html_e( 'Variaciones', 'ltms' ); ?></span>
                </label>
            </div>
        </div>

        <!-- PROD-06: SKU + PROD-05: Peso y dimensiones (solo para physical y restaurant) -->
        <div id="ltms-np-physical-fields" style="margin-bottom:14px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'SKU', 'ltms' ); ?></label>
                    <input type="text" id="ltms-np-sku" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Peso (kg)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-weight" min="0" step="0.01" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Largo (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-length" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Ancho (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-width" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Alto (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-height" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Clase de envío', 'ltms' ); ?></label>
                    <select id="ltms-np-shipping-class" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                        <option value=""><?php esc_html_e( 'Sin clase', 'ltms' ); ?></option>
                        <?php
                        $np_ship_classes = get_terms([ 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ]);
                        if ( ! is_wp_error( $np_ship_classes ) ) :
                            foreach ( $np_ship_classes as $np_sc ) :
                        ?>
                        <option value="<?php echo esc_attr( $np_sc->term_id ); ?>"><?php echo esc_html( $np_sc->name ); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- PROD-03: Archivo descargable (solo para digital) -->
        <div id="ltms-np-digital-fields" style="display:none;margin-bottom:14px;padding:14px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">💾 <?php esc_html_e( 'Archivo descargable', 'ltms' ); ?></label>
            <input type="url" id="ltms-np-download-url" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;margin-bottom:8px;" placeholder="<?php esc_attr_e( 'https://... (URL del archivo a descargar)', 'ltms' ); ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Límite descargas', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-download-limit" min="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( '0 = ilimitado', 'ltms' ); ?>">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Expira (días)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-download-expiry" min="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( '0 = nunca', 'ltms' ); ?>">
                </div>
            </div>
        </div>

        <!-- v2.9.285: Campos de booking/turismo (solo para booking) -->
        <div id="ltms-np-booking-fields" style="display:none;margin-bottom:14px;padding:14px;background:#eff6ff;border:1.5px solid #93c5fd;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:8px;">🏨 <?php esc_html_e( 'Configuración de Reserva (Turismo)', 'ltms' ); ?></label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Tipo de reserva', 'ltms' ); ?></label>
                    <select id="ltms-np-booking-type" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                        <option value="accommodation"><?php esc_html_e( 'Hospedaje (noches)', 'ltms' ); ?></option>
                        <option value="experience"><?php esc_html_e( 'Experiencia (horas)', 'ltms' ); ?></option>
                        <option value="rental"><?php esc_html_e( 'Alquiler', 'ltms' ); ?></option>
                        <option value="professional_service"><?php esc_html_e( 'Servicio profesional', 'ltms' ); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Capacidad (personas)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-booking-capacity" min="1" value="1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Mín. noches', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-min-nights" min="1" value="1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Máx. noches (0=sin límite)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-max-nights" min="0" value="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Check-in', 'ltms' ); ?></label>
                    <input type="time" id="ltms-np-checkin-time" value="15:00" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Check-out', 'ltms' ); ?></label>
                    <input type="time" id="ltms-np-checkout-time" value="11:00" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Modo de pago', 'ltms' ); ?></label>
                    <select id="ltms-np-payment-mode" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                        <option value="full"><?php esc_html_e( 'Pago total', 'ltms' ); ?></option>
                        <option value="deposit"><?php esc_html_e( 'Depósito + saldo', 'ltms' ); ?></option>
                    </select>
                </div>
                <div id="ltms-np-deposit-pct-wrap" style="display:none;">
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Depósito (%)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-np-deposit-pct" min="10" max="90" value="30" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
        </div>

        <!-- AUDIT-PROD-044: Campos de variaciones (solo para variable) — paridad con loadNewProductView (eliminado) -->
        <div id="ltms-np-variable-fields" style="display:none;margin-bottom:14px;padding:14px;background:#faf5ff;border:1.5px solid #ddd6fe;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:8px;">🎨 <?php esc_html_e( 'Variaciones del producto', 'ltms' ); ?></label>
            <p style="font-size:0.78rem;color:#6b7280;margin-bottom:10px;"><?php esc_html_e( 'Define atributos (tallas, colores, etc.) y el precio de cada variación.', 'ltms' ); ?></p>
            <div id="ltms-np-attributes" style="margin-bottom:12px;"></div>
            <button type="button" id="ltms-np-add-attribute" style="padding:8px 14px;border:1.5px dashed #8b5cf6;border-radius:6px;background:#f5f3ff;cursor:pointer;color:#6d28d9;font-size:0.8rem;">+ <?php esc_html_e( 'Agregar atributo (ej: Talla)', 'ltms' ); ?></button>
        </div>

        <!-- PROD-08: Tags -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Etiquetas', 'ltms' ); ?></label>
            <input type="text" id="ltms-np-tags" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Separa con comas: rojo, algodón, verano', 'ltms' ); ?>">
        </div>

        <!-- Categoría -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Categoría', 'ltms' ); ?></label>
            <select id="ltms-np-category" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value=""><?php esc_html_e( 'Sin categoría', 'ltms' ); ?></option>
                <?php
                $np_terms = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 100 ]);
                if ( ! is_wp_error( $np_terms ) ) :
                    foreach ( $np_terms as $np_term ) :
                ?>
                <option value="<?php echo esc_attr( $np_term->term_id ); ?>"><?php echo esc_html( $np_term->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <!-- Estado -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Estado al Publicar', 'ltms' ); ?></label>
            <select id="ltms-np-status" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="pending"><?php esc_html_e( 'Pendiente de revisión', 'ltms' ); ?></option>
                <option value="draft"><?php esc_html_e( 'Borrador', 'ltms' ); ?></option>
                <option value="publish"><?php esc_html_e( 'Publicado directamente', 'ltms' ); ?></option>
            </select>
        </div>

        <?php // AUDIT-PROD-044: visibilidad en catálogo — paridad con loadNewProductView (eliminado). ?>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Visibilidad en catálogo', 'ltms' ); ?></label>
            <select id="ltms-np-visibility" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="visible"><?php esc_html_e( 'Visible en catálogo y búsqueda', 'ltms' ); ?></option>
                <option value="catalog"><?php esc_html_e( 'Solo en catálogo', 'ltms' ); ?></option>
                <option value="search"><?php esc_html_e( 'Solo en búsqueda', 'ltms' ); ?></option>
                <option value="hidden"><?php esc_html_e( 'Oculto (no visible)', 'ltms' ); ?></option>
            </select>
        </div>

        <!-- CS-08: ReDi toggle + tasa -->
        <?php
        // M-QA-11: ltms_redi_min_rate / max_rate / default_rate siempre se guardan en DB
        // como decimal [0,1] (todo campo "*_rate" pasa por el sanitizador de
        // class-ltms-admin-settings.php, que normaliza a decimal). Deben multiplicarse
        // por 100 para mostrarse como porcentaje en min/max/value/label del input —
        // de lo contrario el campo queda con rango "mín 0.05%, máx 0.4%" (ver QA sesión).
        $ltms_redi_min     = round( (float) get_option( 'ltms_redi_min_rate', 0.05 ) * 100, 2 );
        $ltms_redi_max     = round( (float) get_option( 'ltms_redi_max_rate', 0.40 ) * 100, 2 );
        $ltms_redi_default = round( (float) get_option( 'ltms_redi_default_rate', 0.15 ) * 100, 2 );
        if ( 'yes' === get_option('ltms_redi_enabled') ) : ?>
        <div style="margin-bottom:16px;padding:14px;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:8px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <input type="checkbox" id="ltms-np-redi-enabled" style="width:18px;height:18px;accent-color:#1a5276;cursor:pointer;">
                <label for="ltms-np-redi-enabled" style="font-weight:600;font-size:0.9rem;cursor:pointer;">
                    🔁 <?php esc_html_e( 'Habilitar distribución ReDi', 'ltms' ); ?>
                </label>
            </div>
            <div id="ltms-np-redi-rate-wrap" style="display:none;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php printf( esc_html__( 'Comisión para revendedor (%% — mín %s%%, máx %s%%)', 'ltms' ), $ltms_redi_min, $ltms_redi_max ); ?>
                </label>
                <input type="number" id="ltms-np-redi-rate"
                    min="<?php echo esc_attr( $ltms_redi_min ); ?>"
                    max="<?php echo esc_attr( $ltms_redi_max ); ?>"
                    step="1"
                    placeholder="<?php echo esc_attr( $ltms_redi_default ); ?>"
                    value="<?php echo esc_attr( $ltms_redi_default ); ?>"
                    style="width:100%;padding:9px 12px;border:1.5px solid #93c5fd;border-radius:6px;box-sizing:border-box;">
                <p style="font-size:0.8rem;color:#4b5563;margin-top:4px;">
                    <?php esc_html_e( 'Porcentaje del precio de venta que recibirá el revendedor al distribuir tu producto.', 'ltms' ); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" style="padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-np-submit" style="padding:10px 22px;background:#1a5276;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                ➕ <span id="ltms-np-btn-text"><?php esc_html_e( 'Crear Producto', 'ltms' ); ?></span>
            </button>
        </div>
    </div>
</div>

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-products.js
wp_enqueue_script( 'ltms-products', ltms_asset_url( 'js/ltms-products' ), [ 'jquery' ], LTMS_VERSION, true );
?>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: Editar Producto  (id="ltms-modal-edit-product")
     CS-07: edición inline en panel vendedor sin redirigir a wp-admin
     ═══════════════════════════════════════════════════════════════ -->
<div class="ltms-modal" id="ltms-modal-edit-product" role="dialog" aria-modal="true" aria-labelledby="ltms-ep-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:560px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;max-height:90vh;overflow-y:auto;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 id="ltms-ep-title" style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Editar Producto', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;" aria-label="Cerrar">✕</button>
        </div>

        <input type="hidden" id="ltms-ep-product-id" value="">
        <div id="ltms-ep-notice" class="ltms-modal-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"></div>

        <!-- Imagen -->
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Imagen del Producto', 'ltms' ); ?></label>
            <div id="ltms-ep-img-preview" style="width:100%;height:140px;border:2px dashed #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#f9fafb;overflow:hidden;">
                <span style="color:#9ca3af;font-size:2rem;">📷</span>
            </div>
            <input type="file" id="ltms-ep-img-input" accept="image/*" style="display:none;">
            <input type="hidden" id="ltms-ep-image-id" value="">
            <button type="button" style="margin-top:8px;padding:6px 14px;border:1.5px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;" id="ltms-ep-img-btn">
                📁 <?php esc_html_e( 'Cambiar imagen', 'ltms' ); ?>
            </button>
            <span id="ltms-ep-img-status" style="font-size:0.8rem;color:#6b7280;margin-left:8px;"></span>
        </div>

        <!-- Nombre -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Nombre del Producto *', 'ltms' ); ?></label>
            <input type="text" id="ltms-ep-name" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
        </div>

        <!-- Descripción -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción', 'ltms' ); ?></label>
            <textarea id="ltms-ep-desc" rows="3" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;"></textarea>
        </div>

        <?php // AUDIT-PROD-H3 (re-auditoría): paridad modal Edit → fields de create_product. ?>
        <!-- Descripción corta -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción Corta', 'ltms' ); ?></label>
            <textarea id="ltms-ep-short-desc" rows="2" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;" placeholder="<?php esc_attr_e( 'Resumen breve que aparece junto al precio (máx 200 caracteres)...', 'ltms' ); ?>"></textarea>
        </div>

        <!-- SKU + Etiquetas (2 cols) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'SKU', 'ltms' ); ?></label>
                <input type="text" id="ltms-ep-sku" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>">
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Etiquetas', 'ltms' ); ?></label>
                <input type="text" id="ltms-ep-tags" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( 'rojo, algodón, verano', 'ltms' ); ?>">
            </div>
        </div>

        <?php // AUDIT-PROD-QA-001 P1-A + P2-A: bloque physical-fields en modal Edit. ?>
        <?php // Antes el modal Edit NO tenía Peso/Largo/Ancho/Alto/Clase de envío. ?>
        <?php // Editar un producto físico dejaba el peso congelado sin poder corregir. ?>
        <div id="ltms-ep-physical-fields" style="margin-bottom:14px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Peso (kg)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-weight" min="0" step="0.01" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Largo (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-length" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Ancho (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-width" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Alto (cm)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-height" min="0" step="0.1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="0">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Clase de envío', 'ltms' ); ?></label>
                <select id="ltms-ep-shipping-class" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                    <option value=""><?php esc_html_e( 'Sin clase', 'ltms' ); ?></option>
                    <?php
                    $ep_ship_classes = get_terms([ 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ]);
                    if ( ! is_wp_error( $ep_ship_classes ) ) :
                        foreach ( $ep_ship_classes as $ep_sc ) :
                    ?>
                    <option value="<?php echo esc_attr( $ep_sc->term_id ); ?>"><?php echo esc_html( $ep_sc->name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
        </div>

        <!-- Precio, Oferta y Stock -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Precio *', 'ltms' ); ?></label>
                <input type="number" id="ltms-ep-price" min="0" step="0.01" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
            </div>
            <div>
                <?php // AUDIT-PROD-H4 (re-auditoría): precio de oferta editable en modal Edit.?>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Oferta', 'ltms' ); ?></label>
                <input type="number" id="ltms-ep-sale-price" min="0" step="0.01" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>">
            </div>
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Stock', 'ltms' ); ?></label>
                <input type="number" id="ltms-ep-stock" min="0" step="1" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Vacío = ilimitado', 'ltms' ); ?>">
            </div>
        </div>

        <!-- Tipo — grilla 2×3 con 6 tipos (PROD-01: agregado restaurant + variable) -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Tipo de Producto', 'ltms' ); ?></label>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-physical-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="physical" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">📦 <?php esc_html_e( 'Físico', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-digital-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="digital" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">💾 <?php esc_html_e( 'Digital', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-service-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="service" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">🔧 <?php esc_html_e( 'Servicio', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-booking-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="booking" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">🏨 <?php esc_html_e( 'Turismo', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-restaurant-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="restaurant" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">🍽️ <?php esc_html_e( 'Restaurante', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 10px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-variable-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="variable" style="accent-color:#1a5276;"> <span style="font-size:0.8rem;">🎨 <?php esc_html_e( 'Variaciones', 'ltms' ); ?></span>
                </label>
            </div>
        </div>

        <!-- Categoría -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Categoría', 'ltms' ); ?></label>
            <select id="ltms-ep-category" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value=""><?php esc_html_e( 'Sin categoría', 'ltms' ); ?></option>
                <?php
                $ep_terms = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 100 ]);
                if ( ! is_wp_error( $ep_terms ) ) :
                    foreach ( $ep_terms as $ep_term ) :
                ?>
                <option value="<?php echo esc_attr( $ep_term->term_id ); ?>"><?php echo esc_html( $ep_term->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <!-- Estado -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Estado', 'ltms' ); ?></label>
            <select id="ltms-ep-status" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="pending"><?php esc_html_e( 'Pendiente de revisión', 'ltms' ); ?></option>
                <option value="draft"><?php esc_html_e( 'Borrador', 'ltms' ); ?></option>
                <option value="publish"><?php esc_html_e( 'Publicado', 'ltms' ); ?></option>
            </select>
        </div>

        <?php // AUDIT-PROD-044: visibilidad en catálogo en modal Edit — paridad con get_product (catalog_visibility ya retorna). ?>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Visibilidad en catálogo', 'ltms' ); ?></label>
            <select id="ltms-ep-visibility" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="visible"><?php esc_html_e( 'Visible en catálogo y búsqueda', 'ltms' ); ?></option>
                <option value="catalog"><?php esc_html_e( 'Solo en catálogo', 'ltms' ); ?></option>
                <option value="search"><?php esc_html_e( 'Solo en búsqueda', 'ltms' ); ?></option>
                <option value="hidden"><?php esc_html_e( 'Oculto (no visible)', 'ltms' ); ?></option>
            </select>
        </div>

        <!-- AUDIT-PROD-044: Campos de booking en modal Edit (paridad con modal New + create_product) -->
        <div id="ltms-ep-booking-fields" style="display:none;margin-bottom:14px;padding:14px;background:#eff6ff;border:1.5px solid #93c5fd;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:8px;">🏨 <?php esc_html_e( 'Configuración de Reserva (Turismo)', 'ltms' ); ?></label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Tipo de reserva', 'ltms' ); ?></label>
                    <select id="ltms-ep-booking-type" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                        <option value="accommodation"><?php esc_html_e( 'Hospedaje (noches)', 'ltms' ); ?></option>
                        <option value="experience"><?php esc_html_e( 'Experiencia (horas)', 'ltms' ); ?></option>
                        <option value="rental"><?php esc_html_e( 'Alquiler', 'ltms' ); ?></option>
                        <option value="professional_service"><?php esc_html_e( 'Servicio profesional', 'ltms' ); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Capacidad (personas)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-booking-capacity" min="1" value="1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Mín. noches', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-min-nights" min="1" value="1" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Máx. noches (0=sin límite)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-max-nights" min="0" value="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Check-in', 'ltms' ); ?></label>
                    <input type="time" id="ltms-ep-checkin-time" value="15:00" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Check-out', 'ltms' ); ?></label>
                    <input type="time" id="ltms-ep-checkout-time" value="11:00" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Modo de pago', 'ltms' ); ?></label>
                    <select id="ltms-ep-payment-mode" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                        <option value="full"><?php esc_html_e( 'Pago total', 'ltms' ); ?></option>
                        <option value="deposit"><?php esc_html_e( 'Depósito + saldo', 'ltms' ); ?></option>
                    </select>
                </div>
                <div id="ltms-ep-deposit-pct-wrap" style="display:none;">
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Depósito (%)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-deposit-pct" min="10" max="90" value="30" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;">
                </div>
            </div>
        </div>

        <!-- AUDIT-PROD-H1 (re-auditoría): Archivo descargable para digital en modal Edit (paridad con modal New) -->
        <div id="ltms-ep-digital-fields" style="display:none;margin-bottom:14px;padding:14px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">💾 <?php esc_html_e( 'Archivo descargable', 'ltms' ); ?></label>
            <input type="url" id="ltms-ep-download-url" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;margin-bottom:8px;" placeholder="<?php esc_attr_e( 'https://... (URL del archivo a descargar)', 'ltms' ); ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Límite descargas', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-download-limit" min="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( '0 = ilimitado', 'ltms' ); ?>">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:4px;"><?php esc_html_e( 'Expira (días)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-ep-download-expiry" min="0" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:0.85rem;" placeholder="<?php esc_attr_e( '0 = nunca', 'ltms' ); ?>">
                </div>
            </div>
        </div>

        <!-- AUDIT-PROD-044: Campos de variaciones en modal Edit (paridad con modal New) -->
        <div id="ltms-ep-variable-fields" style="display:none;margin-bottom:14px;padding:14px;background:#faf5ff;border:1.5px solid #ddd6fe;border-radius:8px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:8px;">🎨 <?php esc_html_e( 'Variaciones del producto', 'ltms' ); ?></label>
            <p style="font-size:0.78rem;color:#6b7280;margin-bottom:10px;"><?php esc_html_e( 'Define atributos (tallas, colores, etc.). Al guardar, se recrearán las variaciones.', 'ltms' ); ?></p>
            <div id="ltms-ep-attributes" style="margin-bottom:12px;"></div>
            <button type="button" id="ltms-ep-add-attribute" style="padding:8px 14px;border:1.5px dashed #8b5cf6;border-radius:6px;background:#f5f3ff;cursor:pointer;color:#6d28d9;font-size:0.8rem;">+ <?php esc_html_e( 'Agregar atributo (ej: Talla)', 'ltms' ); ?></button>
        </div>

        <!-- CS-08: ReDi toggle + tasa (edición) -->
        <?php if ( 'yes' === get_option('ltms_redi_enabled') ) : ?>
        <div id="ltms-ep-redi-wrap" style="margin-bottom:16px;padding:14px;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:8px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <input type="checkbox" id="ltms-ep-redi-enabled" style="width:18px;height:18px;accent-color:#1a5276;cursor:pointer;">
                <label for="ltms-ep-redi-enabled" style="font-weight:600;font-size:0.9rem;cursor:pointer;">
                    🔁 <?php esc_html_e( 'Habilitar distribución ReDi', 'ltms' ); ?>
                </label>
            </div>
            <div id="ltms-ep-redi-rate-wrap" style="display:none;">
                <?php
                // M-QA-11: misma normalización decimal→porcentaje que en el modal de creación.
                $ltms_redi_min = round( (float) get_option( 'ltms_redi_min_rate', 0.05 ) * 100, 2 );
                $ltms_redi_max = round( (float) get_option( 'ltms_redi_max_rate', 0.40 ) * 100, 2 );
                ?>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php printf( esc_html__( 'Comisión para revendedor (%% — mín %s%%, máx %s%%)', 'ltms' ), $ltms_redi_min, $ltms_redi_max ); ?>
                </label>
                <input type="number" id="ltms-ep-redi-rate"
                    min="<?php echo esc_attr( $ltms_redi_min ); ?>"
                    max="<?php echo esc_attr( $ltms_redi_max ); ?>"
                    step="1"
                    style="width:100%;padding:9px 12px;border:1.5px solid #93c5fd;border-radius:6px;box-sizing:border-box;">
                <p style="font-size:0.8rem;color:#4b5563;margin-top:4px;">
                    <?php esc_html_e( 'Porcentaje del precio de venta que recibirá el revendedor al distribuir tu producto.', 'ltms' ); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" style="padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-ep-submit" style="padding:10px 22px;background:#1a5276;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                💾 <?php esc_html_e( 'Guardar Cambios', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: Eliminar Producto  (id="ltms-modal-delete-product")
     FIX-P1-BATCH-A: replaces native confirm() with an accessible
     dialog (role/aria-modal/aria-labelledby/tabindex) — mirrors the
     WCAG pattern already used in view-envios.php.
     ═══════════════════════════════════════════════════════════════ -->
<div class="ltms-modal" id="ltms-modal-delete-product"
     role="dialog" aria-modal="true" aria-labelledby="ltms-dp-title" tabindex="-1">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" role="document" style="max-width:420px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 id="ltms-dp-title" style="margin:0;font-size:1.1rem;color:#111827;">
                ⚠️ <?php esc_html_e( 'Eliminar producto', 'ltms' ); ?>
            </h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>">✕</button>
        </div>
        <p style="margin:0 0 8px;font-size:0.875rem;color:#374151;">
            <?php esc_html_e( '¿Eliminar el producto', 'ltms' ); ?>
            <strong id="ltms-dp-name" style="font-weight:600;"></strong>?
        </p>
        <p style="margin:0 0 20px;font-size:0.78rem;color:#6b7280;">
            <?php esc_html_e( 'Esta acción no se puede deshacer.', 'ltms' ); ?>
        </p>
        <div id="ltms-dp-notice" class="ltms-modal-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"></div>
        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" style="padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-dp-confirm" style="padding:10px 22px;background:#dc2626;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🗑 <?php esc_html_e( 'Eliminar', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

