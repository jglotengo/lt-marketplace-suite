<?php
/**
 * Vista: Envíos — Relaciones de Envíos del Vendedor
 *
 * Permite al vendedor crear manifiestos de despacho (Relaciones de Envíos)
 * agrupando guías por transportadora, con autocompletado de destinatarios
 * desde Aveonline.
 *
 * @package LTMS
 * @version 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Obtener transportadoras disponibles
$carriers = class_exists( 'LTMS_Business_Aveonline_Carriers' )
    ? LTMS_Business_Aveonline_Carriers::all()
    : [];
?>

<div class="ltms-view-pad">

    <!-- Header -->
    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;font-size:1.35rem;font-weight:700;color:#111827;">
            🚚 <?php esc_html_e( 'Centro de Envíos', 'ltms' ); ?>
        </h2>
        <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">
            <?php esc_html_e( 'Gestiona todos tus envíos: relaciones Aveonline, tracking de carriers, y estados de entrega.', 'ltms' ); ?>
        </p>
    </div>

    <!-- AUDIT-SHIPPING-ENGINE #19 FIX: Stats unificadas de envíos por carrier. -->
    <?php
    $vendor_id = get_current_user_id();
    global $wpdb;
    $ship_stats = [
        'aveonline' => 0,
        'deprisa'   => 0,
        'heka'      => 0,
        'uber'      => 0,
        'pickup'    => 0,
        'own'       => 0,
    ];
    // Count orders by shipping method for this vendor.
    $ship_counts = $wpdb->get_results( $wpdb->prepare(
        "SELECT om.meta_value AS method_id, COUNT(DISTINCT om.order_id) AS cnt
         FROM {$wpdb->prefix}wc_orders_meta om
         INNER JOIN {$wpdb->prefix}wc_orders_meta om2 ON om.order_id = om2.order_id AND om2.meta_key = '_ltms_vendor_id' AND om2.meta_value = %s
         WHERE om.meta_key = '_shipping_method_id' OR om.meta_key = '_ltms_shipping_method'
         GROUP BY om.meta_value",
        $vendor_id
    ), ARRAY_A );
    if ( empty( $ship_counts ) ) {
        // Legacy postmeta path.
        $ship_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, ':', 1), ':', -1) AS method_id, COUNT(DISTINCT pm.post_id) AS cnt
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_ltms_vendor_id' AND pm2.meta_value = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
             WHERE pm.meta_key = '_shipping_method_id' OR pm.meta_key = '_ltms_shipping_method'
             GROUP BY method_id",
            $vendor_id
        ), ARRAY_A );
    }
    if ( $ship_counts ) {
        foreach ( $ship_counts as $row ) {
            $mid = strtolower( $row['method_id'] ?? '' );
            if ( strpos( $mid, 'aveonline' ) !== false ) $ship_stats['aveonline'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'deprisa' ) !== false ) $ship_stats['deprisa'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'heka' ) !== false ) $ship_stats['heka'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'uber' ) !== false ) $ship_stats['uber'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'pickup' ) !== false ) $ship_stats['pickup'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'own' ) !== false ) $ship_stats['own'] = (int) $row['cnt'];
        }
    }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:20px;">
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#1a5276;"><?php echo $ship_stats['aveonline']; ?></div>
            <div style="font-size:0.7rem;color:#666;">📦 Aveonline</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#dc2626;"><?php echo $ship_stats['deprisa']; ?></div>
            <div style="font-size:0.7rem;color:#666;">📮 Deprisa</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#7c3aed;"><?php echo $ship_stats['heka']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🚀 Heka</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#111;"><?php echo $ship_stats['uber']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🚗 Uber</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#16a34a;"><?php echo $ship_stats['pickup']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🏪 Pickup</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#d97706;"><?php echo $ship_stats['own']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🛵 Domiciliario</div>
        </div>
    </div>

    <!-- ── CREAR RELACIÓN ─────────────────────────────────────────────── -->
    <div class="ltms-card" style="margin-bottom:24px;">
        <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:8px;">
            <span style="font-size:1rem;">📋</span>
            <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                <?php esc_html_e( 'Crear nueva relación', 'ltms' ); ?>
            </h3>
        </div>
        <div class="ltms-card-body" style="padding:20px;">

            <!-- Autocomplete destinatarios -->
            <div style="margin-bottom:20px;padding:14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                <div style="font-size:0.78rem;font-weight:600;color:#0284c7;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.4px;">
                    🔍 <?php esc_html_e( 'Buscar destinatario en Aveonline (opcional)', 'ltms' ); ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="ltms-envios-recipient-search"
                           placeholder="<?php esc_attr_e( 'Nombre, email o documento (mín. 3 caracteres)…', 'ltms' ); ?>"
                           style="flex:1;padding:8px 12px;border:1px solid #bae6fd;border-radius:6px;font-size:0.85rem;outline:none;" />
                    <button type="button" id="ltms-envios-recipient-btn"
                            class="ltms-btn ltms-btn-sm" style="white-space:nowrap;background:#0284c7;color:#fff;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:0.82rem;">
                        <?php esc_html_e( 'Buscar', 'ltms' ); ?>
                    </button>
                </div>
                <div id="ltms-envios-recipient-results" style="margin-top:8px;display:none;"></div>
            </div>

            <!-- Formulario -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                        <?php esc_html_e( 'Transportadora *', 'ltms' ); ?>
                    </label>
                    <select id="ltms-envios-carrier"
                            style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;background:#fff;outline:none;">
                        <option value=""><?php esc_html_e( '— Selecciona transportadora —', 'ltms' ); ?></option>
                        <?php foreach ( $carriers as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>">
                                <?php echo esc_html( $c['label'] ?? $c['id'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                        <?php esc_html_e( 'Número(s) de guía *', 'ltms' ); ?>
                    </label>
                    <textarea id="ltms-envios-guias" rows="2"
                              placeholder="<?php esc_attr_e( 'Ej: 044013783462, 034033950937', 'ltms' ); ?>"
                              style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;resize:vertical;font-family:monospace;outline:none;box-sizing:border-box;"></textarea>
                    <span style="font-size:0.72rem;color:#9ca3af;"><?php esc_html_e( 'Separa múltiples guías con comas.', 'ltms' ); ?></span>
                </div>
            </div>

            <button type="button" id="ltms-envios-create-btn"
                    class="ltms-btn" style="background:#059669;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-size:0.85rem;font-weight:600;cursor:pointer;">
                ✅ <?php esc_html_e( 'Crear relación de envío', 'ltms' ); ?>
            </button>

            <!-- Resultado de creación -->
            <div id="ltms-envios-create-result" style="display:none;margin-top:16px;"></div>
        </div>
    </div>

    <!-- ── MIS RELACIONES ─────────────────────────────────────────────── -->
    <div class="ltms-card">
        <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span>📦</span>
                <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                    <?php esc_html_e( 'Mis relaciones', 'ltms' ); ?>
                </h3>
            </div>
            <button type="button" id="ltms-envios-refresh-btn"
                    style="background:none;border:1px solid #d1d5db;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:0.78rem;color:#6b7280;">
                🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
        </div>
        <div class="ltms-card-body" style="padding:0;">
            <div id="ltms-envios-list-wrap" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( '# Relación', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Transportadora', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Guías', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ltms-envios-tbody">
                        <tr>
                            <td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">
                                <?php esc_html_e( 'Cargando…', 'ltms' ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación de eliminación -->
    <!-- EV-BUG-4: WCAG 2.1 — added role="dialog", aria-modal, aria-labelledby,
         tabindex="-1" for keyboard focus, and role="document" on the inner
         container. Keyboard handling (Escape to close, focus trap, focus
         restoration) is wired up in the <script> below. -->
    <div id="ltms-envios-delete-modal"
         role="dialog" aria-modal="true" aria-labelledby="ltms-envios-delete-title" tabindex="-1"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
        <div role="document" style="background:#fff;border-radius:12px;padding:28px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <h4 id="ltms-envios-delete-title" style="margin:0 0 12px;font-size:1rem;color:#111827;">⚠️ <?php esc_html_e( 'Eliminar relación', 'ltms' ); ?></h4>
            <p style="margin:0 0 20px;font-size:0.85rem;color:#6b7280;" id="ltms-envios-delete-msg"></p>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="ltms-envios-delete-cancel"
                        style="padding:8px 16px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:0.82rem;">
                    <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                </button>
                <button type="button" id="ltms-envios-delete-confirm"
                        style="padding:8px 16px;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.82rem;font-weight:600;">
                    <?php esc_html_e( 'Eliminar', 'ltms' ); ?>
                </button>
            </div>
        </div>
    </div>

</div><!-- /ltms-view-pad -->

<script>
(function($) {
    'use strict';

    const nonce   = '<?php echo esc_js( wp_create_nonce( 'ltms_vendor_nonce' ) ); ?>';
    const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    let deleteTarget = null;
    let modalPreviousFocus = null;

    // ── Security helpers (EV-BUG-2 / EV-BUG-3) ───────────────────────────

    // EV-BUG-2: Escape Aveonline API data before inserting into HTML to
    // prevent stored XSS via recipient search. Escapes &, <, >, ", ' — safe
    // for both text content AND attribute values.
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = (text === null || text === undefined) ? '' : String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // EV-BUG-3: Block javascript:, data:, vbscript: URLs from being injected
    // into href attributes. Only http(s) is allowed; anything else becomes '#'.
    function safeUrl(url) {
        if (!url) return '#';
        if (/^https?:\/\//i.test(url)) return url;
        return '#';
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    function showMsg(el, html, type) {
        const colors = {
            success: { bg: '#f0fdf4', border: '#bbf7d0', color: '#166534' },
            error:   { bg: '#fef2f2', border: '#fecaca', color: '#991b1b' },
            info:    { bg: '#eff6ff', border: '#bfdbfe', color: '#1d4ed8' },
        };
        const c = colors[type] || colors.info;
        $(el).html(`<div style="padding:12px 16px;background:${c.bg};border:1px solid ${c.border};border-radius:8px;color:${c.color};font-size:0.85rem;">${html}</div>`).show();
    }

    function formatDate(dt) {
        if (!dt) return '—';
        return dt.replace('T', ' ').substring(0, 16);
    }

    // ── Cargar mis relaciones ─────────────────────────────────────────────
    function loadRelations() {
        $('#ltms-envios-tbody').html('<tr><td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">Cargando…</td></tr>');
        $.post(ajaxUrl, { action: 'ltms_vendor_list_relations', nonce }, function(res) {
            if (!res.success || !res.data.relations.length) {
                $('#ltms-envios-tbody').html('<tr><td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">Sin relaciones registradas.</td></tr>');
                return;
            }
            let rows = '';
            res.data.relations.forEach(r => {
                const guias = (r.guias || '').split(',').map(g => g.trim()).filter(Boolean);
                const guiasBadge = guias.slice(0, 2).map(g =>
                    `<span style="display:inline-block;padding:2px 6px;background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:0.72rem;font-family:monospace;margin:1px;">${g}</span>`
                ).join('') + (guias.length > 2 ? `<span style="font-size:0.72rem;color:#9ca3af;"> +${guias.length - 2}</span>` : '');

                rows += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 16px;font-family:monospace;font-size:0.78rem;color:#4b5563;">${r.relacionenvio}</td>
                    <td style="padding:10px 16px;color:#374151;">${r.transportadora || '—'}</td>
                    <td style="padding:10px 16px;">${guiasBadge}</td>
                    <td style="padding:10px 16px;color:#6b7280;white-space:nowrap;">${formatDate(r.fecha_aveonline || r.created_at)}</td>
                    <td style="padding:10px 16px;text-align:center;">
                        <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                            ${r.rutaimpresion ? `<a href="${safeUrl(r.rutaimpresion)}" target="_blank" rel="noopener noreferrer" style="padding:4px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:0.75rem;text-decoration:none;white-space:nowrap;">🖨 Imprimir</a>` : ''}
                            <button type="button" class="ltms-envios-delete-btn" data-relacion="${r.relacionenvio}"
                                    style="padding:4px 10px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:5px;font-size:0.75rem;cursor:pointer;white-space:nowrap;">
                                🗑 Eliminar
                            </button>
                        </div>
                    </td>
                </tr>`;
            });
            $('#ltms-envios-tbody').html(rows);
        });
    }

    // ── Crear relación ────────────────────────────────────────────────────
    $('#ltms-envios-create-btn').on('click', function() {
        const carrier = $('#ltms-envios-carrier').val();
        const guias   = $('#ltms-envios-guias').val().trim();
        const $res    = $('#ltms-envios-create-result');

        if (!carrier || !guias) {
            showMsg($res, 'Selecciona una transportadora e ingresa al menos un número de guía.', 'error');
            return;
        }

        $(this).prop('disabled', true).text('Creando…');
        $res.hide();

        $.post(ajaxUrl, { action: 'ltms_vendor_create_relation', nonce, transportadora: carrier, guias }, function(res) {
            $('#ltms-envios-create-btn').prop('disabled', false).text('✅ Crear relación de envío');
            if (!res.success) {
                showMsg($res, '❌ ' + (res.data?.message || 'Error desconocido.'), 'error');
                return;
            }
            const d = res.data;
            const printBtn = d.rutaimpresion
                ? `<a href="${safeUrl(d.rutaimpresion)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:10px;padding:7px 14px;background:#1d4ed8;color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;">🖨 Imprimir manifiesto</a>`
                : '';
            showMsg($res, `✅ Relación creada: <strong style="font-family:monospace;">${d.relacionenvio}</strong><br><span style="font-size:0.8rem;color:#6b7280;">${d.fecha}</span>${printBtn}`, 'success');
            $('#ltms-envios-guias').val('');
            loadRelations();
        });
    });

    // ── Buscar destinatarios (autocomplete) ──────────────────────────────
    let searchTimer;
    $('#ltms-envios-recipient-search').on('input', function() {
        clearTimeout(searchTimer);
        const val = $(this).val().trim();
        if (val.length < 3) { $('#ltms-envios-recipient-results').hide(); return; }
        searchTimer = setTimeout(() => searchRecipients(val), 400);
    });

    $('#ltms-envios-recipient-btn').on('click', function() {
        const val = $('#ltms-envios-recipient-search').val().trim();
        if (val.length >= 3) searchRecipients(val);
    });

    function searchRecipients(param) {
        $('#ltms-envios-recipient-results').html('<span style="font-size:0.78rem;color:#6b7280;">Buscando…</span>').show();
        $.get(ajaxUrl, { action: 'ltms_aveonline_search_recipients', nonce, param }, function(res) {
            const $r = $('#ltms-envios-recipient-results');
            if (!res.success || !res.data.destinatarios.length) {
                $r.html('<span style="font-size:0.78rem;color:#9ca3af;">Sin resultados.</span>').show();
                return;
            }
            let html = '<div style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;">';
            res.data.destinatarios.forEach(d => {
                // EV-BUG-2: escape all Aveonline-provided fields before inserting
                // into HTML (both attributes and text content) to prevent stored XSS.
                // role="button" + tabindex="0" make each result keyboard-accessible
                // (WCAG 2.1 — see Enter/Space handler below).
                html += `<div class="ltms-recipient-item" role="button" tabindex="0" style="padding:8px 10px;background:#fff;border:1px solid #e0f2fe;border-radius:6px;cursor:pointer;font-size:0.8rem;" data-nombre="${escapeHtml(d.nombre)}" data-direccion="${escapeHtml(d.direccion)}" data-telefono="${escapeHtml(d.telefono || '')}">
                    <strong>${escapeHtml(d.nombre)}</strong> — ${escapeHtml(d.direccion)} <span style="color:#9ca3af;font-size:0.72rem;">${escapeHtml(d.telefono || '')}</span>
                </div>`;
            });
            html += '</div>';
            $r.html(html).show();
        });
    }

    // Al seleccionar un destinatario, poner su nombre en el campo de guías como referencia
    $(document).on('click', '.ltms-recipient-item', function() {
        const nombre = $(this).data('nombre');
        // Insertar referencia del destinatario en las notas del formulario (informativo)
        $('#ltms-envios-recipient-search').val(nombre);
        $('#ltms-envios-recipient-results').hide();
    });

    // EV-BUG-4 (WCAG 2.1): keyboard support for recipient items — Enter or Space
    // activates the item the same as a click.
    $(document).on('keydown', '.ltms-recipient-item', function(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

    // ── Eliminar relación ─────────────────────────────────────────────────
    // EV-BUG-4 (WCAG 2.1): modal management with focus restoration + Escape
    // to close + basic Tab focus trap. openDeleteModal() saves the currently
    // focused element so we can return focus to it on close.
    function openDeleteModal(relacion) {
        deleteTarget = relacion;
        $('#ltms-envios-delete-msg').text(`¿Eliminar la relación ${relacion} en Aveonline? Esta acción no se puede deshacer.`);
        modalPreviousFocus = document.activeElement;
        $('#ltms-envios-delete-modal').css('display', 'flex');
        // Move focus into the modal so screen readers announce it and keyboard
        // users can immediately Tab through the dialog actions.
        setTimeout(function() { $('#ltms-envios-delete-cancel').trigger('focus'); }, 0);
        $(document).on('keydown.ltms-modal', handleModalKeydown);
    }

    function closeDeleteModal() {
        $('#ltms-envios-delete-modal').hide();
        $(document).off('keydown.ltms-modal', handleModalKeydown);
        deleteTarget = null;
        if (modalPreviousFocus && typeof modalPreviousFocus.focus === 'function') {
            modalPreviousFocus.focus();
            modalPreviousFocus = null;
        }
    }

    function handleModalKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeDeleteModal();
            return;
        }
        if (e.key === 'Tab') {
            // Focus trap: keep Tab within the modal's focusable elements.
            const $focusable = $('#ltms-envios-delete-modal').find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
                .filter(':visible:not(:disabled)');
            if (!$focusable.length) return;
            const first = $focusable.first()[0];
            const last  = $focusable.last()[0];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    $(document).on('click', '.ltms-envios-delete-btn', function() {
        openDeleteModal($(this).data('relacion'));
    });

    $('#ltms-envios-delete-cancel').on('click', function() {
        closeDeleteModal();
    });

    $('#ltms-envios-delete-confirm').on('click', function() {
        if (!deleteTarget) return;
        $(this).prop('disabled', true).text('Eliminando…');

        $.post(ajaxUrl, { action: 'ltms_vendor_delete_relation', nonce, numero_relacion: deleteTarget }, function(res) {
            $('#ltms-envios-delete-confirm').prop('disabled', false).text('Eliminar');
            closeDeleteModal();
            if (res.success) {
                loadRelations();
            } else {
                LTMS.UX.toastError('Error', res.data?.message || 'Error al eliminar.');
            }
        });
    });

    $('#ltms-envios-refresh-btn').on('click', loadRelations);

    // ── Init ──────────────────────────────────────────────────────────────
    loadRelations();

})(jQuery);
</script>
