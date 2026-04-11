<?php
/**
 * Vista SPA: Home del Dashboard del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Resumen del Mes', 'ltms' ); ?></h2>
        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                onclick="LTMS.Dashboard.loadView('home', true)">
            🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
        </button>
    </div>

    <!-- Métricas -->
    <div class="ltms-metrics-grid">
        <div class="ltms-metric">
            <div class="ltms-metric-icon blue">💰</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Ventas del Mes', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-sales">...</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon green">📦</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Pedidos', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-orders">...</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon orange">💵</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Comisiones', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-commissions">...</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon purple">👜</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Balance Billetera', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-balance">...</div>
        </div>
    </div>

    <!-- Gráfica de ventas -->
    <div class="ltms-card" style="margin-bottom:24px;">
        <div class="ltms-card-header">
            <?php esc_html_e( 'Evolución de Ventas y Comisiones', 'ltms' ); ?>
        </div>
        <div class="ltms-card-body" style="height:280px;">
            <canvas id="ltms-vendor-sales-chart"></canvas>
        </div>
    </div>

    <!-- Botones de acción rápida -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
        <button type="button" class="ltms-btn ltms-btn-primary" onclick="LTMS.Dashboard.openPayoutModal()">
            💸 <?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-btn ltms-btn-outline" onclick="LTMS.Dashboard.loadView('orders')">
            📦 <?php esc_html_e( 'Ver Pedidos', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-btn ltms-btn-outline" onclick="LTMS.Dashboard.loadView('products')">
            ➕ <?php esc_html_e( 'Agregar Producto', 'ltms' ); ?>
        </button>
    </div>

</div>
