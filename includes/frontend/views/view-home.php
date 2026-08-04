<?php
/**
 * Vista SPA: Home del Dashboard del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// FIX-P1-BATCH-A: $user_id may be undefined when this view is loaded via
// [ltms_vendor_store] shortcode context (no outer variable scope). Resolve
// from the current session and bail safely for anonymous visitors.
$user_id = get_current_user_id();
if ( ! $user_id ) {
        return;
}
?>
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Resumen del Mes', 'ltms' ); ?></h2>
        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                data-action="load-view" data-view="home" data-refresh="1">
            🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
        </button>
    </div>

    <!-- M-AUDIT-REG-07: banner de onboarding — oculto por defecto, lo llena
         renderOnboardingBanner() en ltms-dashboard.js si quedan pasos pendientes. -->
    <div id="ltms-onboarding-banner" style="display:none;margin-bottom:24px;"></div>

    <?php
    // REG-AUDIT-002 F2: banner server-side de "email verificado" que se muestra
    // INMEDIATAMENTE al llegar al dashboard tras clic en el link de verificación
    // (?email_verified=1). Refuerza al vendedor "ya estás dentro, ahora sigue el
    // checklist de onboarding". El banner AJAX de abajo (#ltms-onboarding-banner)
    // aparece tras la llamada ajax_get_dashboard_data — este banner estático
    // aparece antes para no dejar al usuario mirando una pantalla en blanco.
    if ( isset( $_GET['email_verified'] ) && $_GET['email_verified'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div id="ltms-email-verified-banner" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border:1px solid #16a34a;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;">
            <div style="font-size:2.5rem;flex-shrink:0;">✅</div>
            <div style="flex:1;">
                <h3 style="margin:0 0 4px;color:#14532d;font-size:1.15rem;font-weight:800;">¡Email verificado! Ya estás dentro de tu panel</h3>
                <p style="margin:0;color:#166534;font-size:0.9rem;line-height:1.5;">
                    Tu cuenta está activa. Para empezar a vender, completa los <strong>4 pasos</strong> del checklist de bienvenida que aparece debajo. Empieza por <strong>verificar tu identidad (KYC)</strong> — sin eso no podemos aprobar tus pagos.
                </p>
            </div>
        </div>
        <script>
        // Auto-ocultar tras 8 segundos (no molesta al vendor una vez leyó el checklist)
        setTimeout(function(){
            var b = document.getElementById('ltms-email-verified-banner');
            if (b) { b.style.transition='opacity .4s'; b.style.opacity='0'; setTimeout(function(){ b.style.display='none'; }, 400); }
        }, 8000);
        </script>
        <?php
    }
    ?>

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
                    data-action="load-view" data-view="redi">
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
        <button type="button" class="ltms-btn ltms-btn-primary" data-action="open-payout">
            💸 <?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-btn ltms-btn-outline" data-action="load-view" data-view="orders">
            📦 <?php esc_html_e( 'Ver Pedidos', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-btn ltms-btn-outline" data-action="load-view" data-view="products">
            ➕ <?php esc_html_e( 'Agregar Producto', 'ltms' ); ?>
        </button>
    </div>

    <!-- v2.9.90 P2: Widgets adicionales (Woodmart-inspired) -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px;" class="ltms-home-widgets">
        <!-- Ventas recientes -->
        <div class="ltms-card">
            <div class="ltms-card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>📦 <?php esc_html_e( 'Pedidos Recientes', 'ltms' ); ?></span>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" data-action="load-view" data-view="orders" style="font-size:0.7rem;padding:4px 10px;">
                    <?php esc_html_e( 'Ver todos', 'ltms' ); ?>
                </button>
            </div>
            <div class="ltms-card-body" style="padding:0;">
                <div id="ltms-home-recent-orders" style="padding:12px;">
                    <div style="text-align:center;color:#9ca3af;padding:20px;font-size:0.85rem;">
                        <div style="display:inline-block;width:20px;height:20px;border:2px solid #e5e7eb;border-top:2px solid #2563eb;border-radius:50%;animation:ltms-pulse 1s linear infinite;margin-bottom:8px;"></div>
                        <div><?php esc_html_e( 'Cargando...', 'ltms' ); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top productos -->
        <div class="ltms-card">
            <div class="ltms-card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>🏆 <?php esc_html_e( 'Productos Más Vendidos', 'ltms' ); ?></span>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" data-action="load-view" data-view="products" style="font-size:0.7rem;padding:4px 10px;">
                    <?php esc_html_e( 'Ver todos', 'ltms' ); ?>
                </button>
            </div>
            <div class="ltms-card-body" style="padding:0;">
                <div id="ltms-home-top-products" style="padding:12px;">
                    <div style="text-align:center;color:#9ca3af;padding:20px;font-size:0.85rem;">
                        <div style="display:inline-block;width:20px;height:20px;border:2px solid #e5e7eb;border-top:2px solid #2563eb;border-radius:50%;animation:ltms-pulse 1s linear infinite;margin-bottom:8px;"></div>
                        <div><?php esc_html_e( 'Cargando...', 'ltms' ); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    @media (max-width: 768px) {
        .ltms-home-widgets { grid-template-columns: 1fr !important; }
    }
    @keyframes ltms-pulse { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>

</div>
