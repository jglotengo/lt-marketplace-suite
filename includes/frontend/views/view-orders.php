<?php
/**
 * Vista SPA: Pedidos del Vendedor
 *
 * @package LTMS
 * @version 1.6.0
 * @since   1.6.0 P-01: filtro ready-for-pickup + columna de tipo de envío
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Pedidos', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <select id="ltms-order-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                <option value="processing"><?php esc_html_e( 'Procesando', 'ltms' ); ?></option>
                <option value="ready-for-pickup"><?php esc_html_e( 'Listo para Recoger', 'ltms' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completado', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelado', 'ltms' ); ?></option>
            </select>
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
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div id="ltms-orders-pagination" style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;"></div>

</div>

<!-- Modal de detalle de pedido (patrón canónico: .ltms-modal + backdrop + inner) -->
<div id="ltms-modal-order-detail" class="ltms-modal" style="display:none;">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:640px;">
        <div style="display:flex;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" aria-label="Cerrar detalle de pedido" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div id="ltms-order-detail-body"></div>
    </div>
</div>
