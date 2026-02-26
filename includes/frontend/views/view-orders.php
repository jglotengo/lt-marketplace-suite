<?php
/**
 * Vista SPA: Pedidos del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Pedidos', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;">
            <select id="ltms-order-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                <option value="processing"><?php esc_html_e( 'Procesando', 'ltms' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completado', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelado', 'ltms' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ltms-card">
        <div class="ltms-card-body" style="padding:0;">
            <table class="ltms-dtable" style="width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Items', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-orders-tbody">
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
