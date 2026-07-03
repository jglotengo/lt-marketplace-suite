<?php
/**
 * Vista: Órdenes de Compra Aveonline
 *
 * Permite al vendedor generar OC en Aveonline y consultar el historial
 * de órdenes registradas localmente.
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ltms-view-pad">

    <!-- Header -->
    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;font-size:1.35rem;font-weight:700;color:#111827;">
            🛒 <?php esc_html_e( 'Órdenes de Compra', 'ltms' ); ?>
        </h2>
        <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">
            <?php esc_html_e( 'Genera órdenes de compra en Aveonline y consulta tu historial.', 'ltms' ); ?>
        </p>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid #e5e7eb;">
        <button type="button" class="ltms-oc-tab active" data-tab="nueva"
                style="padding:10px 20px;background:none;border:none;border-bottom:2px solid #059669;margin-bottom:-2px;font-size:0.875rem;font-weight:600;color:#059669;cursor:pointer;">
            ➕ <?php esc_html_e( 'Nueva Orden', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-oc-tab" data-tab="historial"
                style="padding:10px 20px;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;font-size:0.875rem;font-weight:600;color:#6b7280;cursor:pointer;">
            📋 <?php esc_html_e( 'Mis Órdenes', 'ltms' ); ?>
        </button>
    </div>

    <!-- ── TAB: NUEVA ORDEN ──────────────────────────────────────────────── -->
    <div id="ltms-oc-tab-nueva">

        <div class="ltms-card" style="margin-bottom:20px;">
            <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
                <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                    📝 <?php esc_html_e( 'Datos de la Orden', 'ltms' ); ?>
                </h3>
            </div>
            <div class="ltms-card-body" style="padding:20px;">

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
                    <!-- Número OC -->
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            <?php esc_html_e( 'Número de OC *', 'ltms' ); ?>
                        </label>
                        <input type="text" id="ltms-oc-numero"
                               placeholder="<?php esc_attr_e( 'Ej: OC-2025-001', 'ltms' ); ?>"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;box-sizing:border-box;outline:none;" />
                    </div>
                    <!-- Proveedor -->
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            <?php esc_html_e( 'Proveedor *', 'ltms' ); ?>
                        </label>
                        <select id="ltms-oc-proveedor"
                                style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;background:#fff;outline:none;">
                            <option value=""><?php esc_html_e( 'Cargando proveedores…', 'ltms' ); ?></option>
                        </select>
                    </div>
                    <!-- Modo envío -->
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            <?php esc_html_e( 'Modo de envío', 'ltms' ); ?>
                        </label>
                        <select id="ltms-oc-modoenvio"
                                style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;background:#fff;outline:none;">
                            <option value="1"><?php esc_html_e( '1 — Estándar', 'ltms' ); ?></option>
                            <option value="2"><?php esc_html_e( '2 — Express', 'ltms' ); ?></option>
                        </select>
                    </div>
                </div>

            </div>
        </div>

        <!-- Líneas de detalle -->
        <div class="ltms-card" style="margin-bottom:20px;">
            <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                    📦 <?php esc_html_e( 'Líneas de Detalle', 'ltms' ); ?>
                </h3>
                <button type="button" id="ltms-oc-add-linea"
                        style="padding:6px 14px;background:#059669;color:#fff;border:none;border-radius:6px;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    ＋ <?php esc_html_e( 'Agregar línea', 'ltms' ); ?>
                </button>
            </div>
            <div class="ltms-card-body" style="padding:0;overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;min-width:900px;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'PLU / EAN', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Artículo', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Cant.', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Precio', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Ciudad', 'ltms' ); ?></th>
                            <th style="padding:10px 12px;text-align:center;color:#6b7280;font-weight:600;"></th>
                        </tr>
                    </thead>
                    <tbody id="ltms-oc-lineas-tbody">
                        <!-- Líneas dinámicas -->
                    </tbody>
                </table>
            </div>
            <div id="ltms-oc-empty-msg" style="padding:32px;text-align:center;color:#9ca3af;font-size:0.85rem;">
                <?php esc_html_e( 'Sin líneas. Haz clic en "Agregar línea" para empezar.', 'ltms' ); ?>
            </div>
        </div>

        <!-- Botón generar -->
        <div style="display:flex;align-items:center;gap:12px;">
            <button type="button" id="ltms-oc-generar-btn"
                    style="padding:11px 24px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer;">
                ✅ <?php esc_html_e( 'Generar Orden de Compra', 'ltms' ); ?>
            </button>
            <span style="font-size:0.78rem;color:#9ca3af;"><?php esc_html_e( 'La orden se enviará a Aveonline y quedará registrada en tu historial.', 'ltms' ); ?></span>
        </div>

        <div id="ltms-oc-generar-result" style="display:none;margin-top:16px;"></div>

    </div><!-- /tab-nueva -->

    <!-- ── TAB: HISTORIAL ────────────────────────────────────────────────── -->
    <div id="ltms-oc-tab-historial" style="display:none;">

        <div class="ltms-card">
            <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                    🗂 <?php esc_html_e( 'Mis Órdenes de Compra', 'ltms' ); ?>
                </h3>
                <button type="button" id="ltms-oc-refresh-btn"
                        style="background:none;border:1px solid #d1d5db;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:0.78rem;color:#6b7280;">
                    🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
                </button>
            </div>
            <div class="ltms-card-body" style="padding:0;overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( '# Orden', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Proveedor', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Líneas', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Detalle', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ltms-oc-historial-tbody">
                        <tr>
                            <td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">
                                <?php esc_html_e( 'Cargando…', 'ltms' ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal detalle OC -->
        <div id="ltms-oc-detail-modal"
             style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:0;max-width:800px;width:95%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <h4 style="margin:0;font-size:1rem;color:#111827;">📋 <span id="ltms-oc-detail-title"><?php esc_html_e( 'Detalle de Orden', 'ltms' ); ?></span></h4>
                    <button type="button" id="ltms-oc-detail-close"
                            style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:#6b7280;line-height:1;">✕</button>
                </div>
                <div id="ltms-oc-detail-body" style="padding:20px;overflow-y:auto;flex:1;"></div>
            </div>
        </div>

    </div><!-- /tab-historial -->

</div><!-- /ltms-view-pad -->

<script>
(function($) {
    'use strict';

    const nonce   = '<?php echo esc_js( wp_create_nonce( 'ltms_vendor_nonce' ) ); ?>';
    const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    let lineaCount = 0;

    // ── Helpers ───────────────────────────────────────────────────────────────
    function showMsg(el, html, type) {
        const c = {
            success: { bg:'#f0fdf4', border:'#bbf7d0', color:'#166534' },
            error:   { bg:'#fef2f2', border:'#fecaca', color:'#991b1b' },
            info:    { bg:'#eff6ff', border:'#bfdbfe', color:'#1d4ed8' },
        }[type] || { bg:'#eff6ff', border:'#bfdbfe', color:'#1d4ed8' };
        $(el).html(`<div style="padding:12px 16px;background:${c.bg};border:1px solid ${c.border};border-radius:8px;color:${c.color};font-size:0.85rem;">${html}</div>`).show();
    }

    function formatDate(dt) {
        return dt ? dt.replace('T',' ').substring(0,16) : '—';
    }

    function estadoBadge(estado) {
        const map = {
            GENERADA: { bg:'#d1fae5', color:'#065f46' },
            ERROR:    { bg:'#fee2e2', color:'#991b1b' },
        };
        const s = map[estado] || { bg:'#f3f4f6', color:'#374151' };
        return `<span style="display:inline-block;padding:2px 8px;background:${s.bg};color:${s.color};border-radius:4px;font-size:0.72rem;font-weight:700;">${estado}</span>`;
    }

    // ── Tabs ──────────────────────────────────────────────────────────────────
    $('.ltms-oc-tab').on('click', function() {
        const tab = $(this).data('tab');
        $('.ltms-oc-tab').each(function() {
            const active = $(this).data('tab') === tab;
            $(this).css({
                'border-bottom-color': active ? '#059669' : 'transparent',
                'color': active ? '#059669' : '#6b7280',
            });
        });
        $('#ltms-oc-tab-nueva, #ltms-oc-tab-historial').hide();
        $(`#ltms-oc-tab-${tab}`).show();
        if (tab === 'historial') loadHistorial();
    });

    // ── Cargar proveedores ────────────────────────────────────────────────────
    function loadProveedores() {
        $.post(ajaxUrl, { action: 'ltms_aveonline_oc_proveedores', nonce }, function(res) {
            const $sel = $('#ltms-oc-proveedor');
            if (!res.success || !res.data.proveedores.length) {
                $sel.html('<option value="">— Sin proveedores —</option>');
                return;
            }
            let opts = '<option value="">— Selecciona proveedor —</option>';
            res.data.proveedores.forEach(p => {
                opts += `<option value="${p.idproveedor}">${p.nombreproveedor} (${p.ciudadproveedor})</option>`;
            });
            $sel.html(opts);
        }).fail(function() {
            $('#ltms-oc-proveedor').html('<option value="">— Error al cargar —</option>');
        });
    }

    // ── Agregar línea ─────────────────────────────────────────────────────────
    function addLinea() {
        lineaCount++;
        const idx = lineaCount;
        const row = `<tr id="ltms-oc-linea-${idx}" style="border-bottom:1px solid #f3f4f6;">
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="pedido" placeholder="Pedido"
                       style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;box-sizing:border-box;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="plu" placeholder="PLU"
                       style="width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="ean" placeholder="EAN" style="margin-top:3px;width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="nombre_articulo" placeholder="Nombre del artículo"
                       style="width:150px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;text-align:center;">
                <input type="number" class="ltms-oc-f" data-f="cantidad_solicitada" value="1" min="1"
                       style="width:55px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;text-align:center;" />
            </td>
            <td style="padding:8px 10px;text-align:right;">
                <input type="number" class="ltms-oc-f" data-f="precio" placeholder="0"
                       style="width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;text-align:right;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="cliente" placeholder="Nombre cliente"
                       style="width:130px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="tel" placeholder="Teléfono" style="margin-top:3px;width:130px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="ciudad" placeholder="Ciudad"
                       style="width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="direccion" placeholder="Dirección" style="margin-top:3px;width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;text-align:center;">
                <button type="button" class="ltms-oc-remove-linea" data-idx="${idx}"
                        style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:4px;padding:4px 8px;font-size:0.75rem;cursor:pointer;">✕</button>
            </td>
        </tr>`;
        $('#ltms-oc-lineas-tbody').append(row);
        $('#ltms-oc-empty-msg').hide();
    }

    $('#ltms-oc-add-linea').on('click', addLinea);

    $(document).on('click', '.ltms-oc-remove-linea', function() {
        const idx = $(this).data('idx');
        $(`#ltms-oc-linea-${idx}`).remove();
        if ($('#ltms-oc-lineas-tbody tr').length === 0) {
            $('#ltms-oc-empty-msg').show();
        }
    });

    // ── Leer líneas del DOM ───────────────────────────────────────────────────
    function getLineas() {
        const lineas = [];
        $('#ltms-oc-lineas-tbody tr').each(function() {
            const linea = {};
            $(this).find('.ltms-oc-f').each(function() {
                linea[$(this).data('f')] = $(this).val().trim();
            });
            // Calcular total = precio * cantidad si está vacío
            const qty   = parseInt(linea.cantidad_solicitada, 10) || 1;
            const price = parseInt(linea.precio, 10) || 0;
            linea.total     = linea.total     || String(price * qty);
            linea.valoracion= linea.valoracion|| String(price * qty);
            linea.peso      = linea.peso      || '1';
            lineas.push(linea);
        });
        return lineas;
    }

    // ── Generar OC ────────────────────────────────────────────────────────────
    $('#ltms-oc-generar-btn').on('click', function() {
        const ordencompra  = $('#ltms-oc-numero').val().trim();
        const idproveedor  = $('#ltms-oc-proveedor').val();
        const modoenvio    = $('#ltms-oc-modoenvio').val();
        const lineas       = getLineas();
        const $res         = $('#ltms-oc-generar-result');

        if (!ordencompra) {
            showMsg($res, '❌ Ingresa el número de la orden de compra.', 'error');
            return;
        }
        if (!idproveedor) {
            showMsg($res, '❌ Selecciona un proveedor.', 'error');
            return;
        }
        if (!lineas.length) {
            showMsg($res, '❌ Agrega al menos una línea de detalle.', 'error');
            return;
        }

        $(this).prop('disabled', true).text('Generando…');
        $res.hide();

        $.post(ajaxUrl, {
            action:       'ltms_aveonline_oc_generar',
            nonce,
            ordencompra,
            idproveedor,
            modoenvio,
            detalle: JSON.stringify(lineas),
        }, function(res) {
            $('#ltms-oc-generar-btn').prop('disabled', false).text('✅ Generar Orden de Compra');
            if (!res.success) {
                showMsg($res, `❌ ${res.data?.message || 'Error desconocido.'}`, 'error');
                return;
            }
            showMsg($res, `✅ OC <strong style="font-family:monospace;">${res.data.ordencompra}</strong> generada correctamente en Aveonline.`, 'success');
            // Reset form
            $('#ltms-oc-numero').val('');
            $('#ltms-oc-lineas-tbody').empty();
            $('#ltms-oc-empty-msg').show();
            lineaCount = 0;
        });
    });

    // ── Cargar historial ──────────────────────────────────────────────────────
    function loadHistorial() {
        $('#ltms-oc-historial-tbody').html('<tr><td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">Cargando…</td></tr>');
        $.post(ajaxUrl, { action: 'ltms_aveonline_oc_mis_ordenes', nonce }, function(res) {
            if (!res.success || !res.data.ordenes.length) {
                $('#ltms-oc-historial-tbody').html('<tr><td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">Sin órdenes registradas.</td></tr>');
                return;
            }
            let rows = '';
            res.data.ordenes.forEach(o => {
                rows += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 16px;font-family:monospace;font-size:0.78rem;color:#4b5563;">${o.ordencompra}</td>
                    <td style="padding:10px 16px;color:#374151;">${o.nombreproveedor || '—'}</td>
                    <td style="padding:10px 16px;text-align:center;color:#374151;">${(o.detalle||[]).length}</td>
                    <td style="padding:10px 16px;text-align:center;">${estadoBadge(o.estado)}</td>
                    <td style="padding:10px 16px;color:#6b7280;white-space:nowrap;">${formatDate(o.created_at)}</td>
                    <td style="padding:10px 16px;text-align:center;">
                        <button type="button" class="ltms-oc-ver-detalle" data-oc='${JSON.stringify(o)}'
                                style="padding:4px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:0.75rem;cursor:pointer;">
                            🔍 Ver
                        </button>
                    </td>
                </tr>`;
            });
            $('#ltms-oc-historial-tbody').html(rows);
        });
    }

    $('#ltms-oc-refresh-btn').on('click', loadHistorial);

    // ── Modal detalle ─────────────────────────────────────────────────────────
    $(document).on('click', '.ltms-oc-ver-detalle', function() {
        const o = $(this).data('oc');
        $('#ltms-oc-detail-title').text(`OC ${o.ordencompra} — ${o.nombreproveedor}`);

        const detalle = o.detalle || [];
        let html = '';
        if (detalle.length) {
            html += `<div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Pedido</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Artículo</th>
                            <th style="padding:8px 12px;text-align:center;color:#6b7280;">Cant.</th>
                            <th style="padding:8px 12px;text-align:right;color:#6b7280;">Precio</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Cliente</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Ciudad</th>
                            <th style="padding:8px 12px;text-align:center;color:#6b7280;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>`;
            detalle.forEach(d => {
                html += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:8px 12px;font-family:monospace;font-size:0.75rem;">${d.pedido||'—'}</td>
                    <td style="padding:8px 12px;">${d.nombre_articulo||'—'}</td>
                    <td style="padding:8px 12px;text-align:center;">${d.cantidad||1}</td>
                    <td style="padding:8px 12px;text-align:right;">$${Number(d.precio||0).toLocaleString('es-CO')}</td>
                    <td style="padding:8px 12px;">${d.cliente||'—'}</td>
                    <td style="padding:8px 12px;">${d.ciudad||'—'}</td>
                    <td style="padding:8px 12px;text-align:center;"><span style="padding:2px 6px;background:#dbeafe;color:#1e40af;border-radius:4px;font-size:0.7rem;">${d.estado_linea||'PENDIENTE'}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        } else {
            html = '<p style="color:#9ca3af;text-align:center;padding:24px;">Sin líneas registradas.</p>';
        }

        if (o.mensaje) {
            html += `<div style="margin-top:12px;padding:10px 14px;background:#f9fafb;border-radius:6px;font-size:0.8rem;color:#6b7280;">💬 ${o.mensaje}</div>`;
        }

        $('#ltms-oc-detail-body').html(html);
        $('#ltms-oc-detail-modal').css('display','flex');
    });

    $('#ltms-oc-detail-close').on('click', function() {
        $('#ltms-oc-detail-modal').hide();
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    loadProveedores();

})(jQuery);
</script>
