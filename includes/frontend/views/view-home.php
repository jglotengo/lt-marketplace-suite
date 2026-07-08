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

    <!-- M-AUDIT-REG-07: banner de onboarding — oculto por defecto, lo llena
         renderOnboardingBanner() en ltms-dashboard.js si quedan pasos pendientes. -->
    <div id="ltms-onboarding-banner" style="display:none;margin-bottom:24px;"></div>

    <!-- AUDIT-REDI-UX-GAPS GAP-2 FIX: banner de onboarding ReDi.
         Se muestra si ReDi está habilitado globalmente y el vendor no
         tiene productos ReDi origin ni adopciones como reseller. -->
    <?php
    $redi_enabled = get_option( 'ltms_redi_enabled', 'no' ) === 'yes';
    $has_origin   = class_exists( 'LTMS_Business_Redi_Manager' ) && LTMS_Business_Redi_Manager::count_origin_products_for_vendor( $user_id ) > 0;
    $has_reseller = false;
    if ( class_exists( 'LTMS_Business_Redi_Manager' ) ) {
        global $wpdb;
        $has_reseller = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}lt_redi_agreements` WHERE reseller_vendor_id = %d AND status = 'active'",
            $user_id
        ) ) > 0;
    }
    if ( $redi_enabled && ! $has_origin && ! $has_reseller ) :
    ?>
    <div style="background:linear-gradient(135deg,#1A1A4E,#2D2D6E);color:#fff;padding:20px 24px;border-radius:12px;margin-bottom:24px;display:flex;align-items:center;gap:20px;">
        <div style="font-size:2.5rem;flex-shrink:0;">🔁</div>
        <div style="flex:1;">
            <h3 style="margin:0 0 4px;color:#fff;font-size:1.1rem;">¡Programa ReDi disponible!</h3>
            <p style="margin:0;font-size:0.85rem;opacity:0.9;line-height:1.5;">
                <?php esc_html_e( 'ReDi (Re-venta Directa) te permite distribuir tus productos a través de otros vendedores (origin) o revender productos de otros (reseller). Gana comisiones automáticas en cada venta.', 'ltms' ); ?>
            </p>
        </div>
        <div style="flex-shrink:0;display:flex;flex-direction:column;gap:8px;">
            <button type="button" class="ltms-btn ltms-btn-primary" style="white-space:nowrap;"
                    onclick="LTMS.Dashboard.loadView('redi');">
                <?php esc_html_e( 'Explorar ReDi', 'ltms' ); ?>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Métricas -->
    <div class="ltms-metrics-grid">
        <div class="ltms-metric">
            <div class="ltms-metric-icon blue">💰</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Ventas del Mes', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-sales ltms-skeleton-loading">$0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon green">📦</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Pedidos', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-orders ltms-skeleton-loading">0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon orange">💵</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Comisiones', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-commissions ltms-skeleton-loading">$0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon purple">👜</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Balance Billetera', 'ltms' ); ?></div>
            <div class="ltms-metric-value ltms-metric-balance ltms-skeleton-loading">$0</div>
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
