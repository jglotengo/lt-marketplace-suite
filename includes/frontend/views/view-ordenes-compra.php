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
        <div id="ltms-oc-detail-modal" class="ltms-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-oc-detail-title"
             style="background:rgba(0,0,0,0.55);z-index:9999;">
            <div style="background:#fff;border-radius:12px;padding:0;max-width:800px;width:95%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <h4 style="margin:0;font-size:1rem;color:#111827;">📋 <span id="ltms-oc-detail-title"><?php esc_html_e( 'Detalle de Orden', 'ltms' ); ?></span></h4>
                    <button type="button" id="ltms-oc-detail-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>"
                            style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:#6b7280;line-height:1;">✕</button>
                </div>
                <div id="ltms-oc-detail-body" style="padding:20px;overflow-y:auto;flex:1;"></div>
            </div>
        </div>

    </div><!-- /tab-historial -->

</div><!-- /ltms-view-pad -->

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-ordenes-compra.js
wp_enqueue_script( 'ltms-ordenes-compra', LTMS_ASSETS_URL . 'js/ltms-ordenes-compra.js', [ 'jquery' ], LTMS_VERSION, true );
?>
