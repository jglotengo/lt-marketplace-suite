<?php
/**
 * Vista SPA: Pedidos del Vendedor
 *
 * @package LTMS
 * @version 2.9.80 — UI/UX overhaul: KPIs, search, empty states, skeleton loading
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Pedidos', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <!-- v2.9.80 P1: Search bar -->
            <div style="position:relative;">
                <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:#9ca3af;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="ltms-order-search" placeholder="<?php esc_attr_e( 'Buscar pedido o cliente...', 'ltms' ); ?>"
                       style="padding:8px 12px 8px 32px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;width:220px;">
            </div>
            <select id="ltms-order-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                <option value="processing"><?php esc_html_e( 'Procesando', 'ltms' ); ?></option>
                <option value="ready-for-pickup"><?php esc_html_e( 'Listo para Recoger', 'ltms' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completado', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelado', 'ltms' ); ?></option>
            </select>
            <!-- v2.9.89 P2: Date range selector -->
            <select id="ltms-order-date-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todas las fechas', 'ltms' ); ?></option>
                <option value="today"><?php esc_html_e( 'Hoy', 'ltms' ); ?></option>
                <option value="7days"><?php esc_html_e( 'Últimos 7 días', 'ltms' ); ?></option>
                <option value="30days"><?php esc_html_e( 'Últimos 30 días', 'ltms' ); ?></option>
                <option value="90days"><?php esc_html_e( 'Últimos 90 días', 'ltms' ); ?></option>
                <option value="thisyear"><?php esc_html_e( 'Este año', 'ltms' ); ?></option>
            </select>
        </div>
    </div>

    <!-- v2.9.80 P1: KPIs rápidos -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-stat-card" style="padding:14px;border-radius:10px;background:#fff;border:1px solid #e5e7eb;">
            <span style="font-size:0.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Total', 'ltms' ); ?></span>
            <div style="font-size:1.3rem;font-weight:700;color:#111827;" id="ltms-order-kpi-total">—</div>
        </div>
        <div class="ltms-stat-card" style="padding:14px;border-radius:10px;background:#fff;border:1px solid #e5e7eb;">
            <span style="font-size:0.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></span>
            <div style="font-size:1.3rem;font-weight:700;color:#f59e0b;" id="ltms-order-kpi-pending">—</div>
        </div>
        <div class="ltms-stat-card" style="padding:14px;border-radius:10px;background:#fff;border:1px solid #e5e7eb;">
            <span style="font-size:0.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Completados', 'ltms' ); ?></span>
            <div style="font-size:1.3rem;font-weight:700;color:#10b981;" id="ltms-order-kpi-completed">—</div>
        </div>
        <div class="ltms-stat-card" style="padding:14px;border-radius:10px;background:#fff;border:1px solid #e5e7eb;">
            <span style="font-size:0.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Ingresos', 'ltms' ); ?></span>
            <div style="font-size:1.3rem;font-weight:700;color:#2563eb;" id="ltms-order-kpi-revenue">—</div>
        </div>
    </div>

    <div class="ltms-card">
        <div class="ltms-card-body ltms-table-scroll" style="padding:0;">
            <table class="ltms-dtable" style="width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Items', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Envío', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'ReDi', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-orders-tbody">
                    <tr><td colspan="8" style="text-align:center;padding:40px 20px;color:#9ca3af;">
                        <div style="display:inline-block;width:24px;height:24px;border:3px solid #e5e7eb;border-top:3px solid #2563eb;border-radius:50%;animation:ltms-spin 1s linear infinite;margin-bottom:12px;"></div>
                        <div><?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- v2.9.80 P1: Empty state (hidden by default) -->
    <div id="ltms-orders-empty" style="display:none;text-align:center;padding:60px 20px;">
        <div style="font-size:3rem;margin-bottom:12px;opacity:0.3;">📦</div>
        <h3 style="margin:0 0 8px;color:#374151;font-size:1.1rem;"><?php esc_html_e( 'No tienes pedidos todavía', 'ltms' ); ?></h3>
        <p style="color:#9ca3af;margin:0 0 16px;font-size:0.85rem;"><?php esc_html_e( 'Cuando los clientes compren tus productos, aparecerán aquí.', 'ltms' ); ?></p>
        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" data-action="load-view" data-view="products">
            <?php esc_html_e( 'Publicar mi primer producto', 'ltms' ); ?>
        </button>
    </div>

    <div id="ltms-orders-pagination" style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;"></div>

</div>

<!-- Modal de detalle de pedido -->
<div id="ltms-modal-order-detail" class="ltms-modal" style="display:none;">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:640px;">
        <div style="display:flex;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div id="ltms-order-detail-body"></div>
    </div>
</div>

<style>@keyframes ltms-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>
