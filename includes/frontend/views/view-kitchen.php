<?php
/**
 * Vista: Kitchen Display System (KDS)
 *
 * AUDIT-RESTAURANT-ENGINE FIX: esta vista NO existía — el PHP comentaba
 * "consumed by view-kitchen.php" pero el archivo nunca fue creado.
 * El KDS solo funcionaba si se accedía via ?tab=kds pero no había HTML.
 *
 * v2.9.92: Overhaul completo — audio element, polling automático, KPIs,
 * empty state, skeleton loading, auto-refresh indicator.
 *
 * @package LTMS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$is_restaurant = get_user_meta( $user_id, 'ltms_is_restaurant', true ) === 'yes';
?>
<div class="ltms-view-pad">
    <div class="ltms-view-header">
        <h2>🍳 <?php esc_html_e( 'Kitchen Display', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <!-- v2.9.92 P2: Sound toggle with audio element -->
            <audio id="ltms-kds-audio" preload="auto" style="display:none;">
                <source src="<?php echo esc_url( LTMS_ASSETS_URL . 'sounds/new-order.mp3' ); ?>" type="audio/mpeg">
            </audio>
            <button type="button" id="ltms-kds-sound-toggle" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                    data-sound-on="1" aria-label="<?php esc_attr_e( 'Activar/desactivar sonido', 'ltms' ); ?>">
                🔔 <span id="ltms-kds-sound-label"><?php esc_html_e( 'Sonido: ON', 'ltms' ); ?></span>
            </button>
            <button type="button" id="ltms-kds-refresh-btn" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                    aria-label="<?php esc_attr_e( 'Actualizar pedidos', 'ltms' ); ?>">
                🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
            <!-- v2.9.92 P2: Auto-refresh indicator -->
            <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#6b7280;">
                <input type="checkbox" id="ltms-kds-auto-refresh" checked style="cursor:pointer;">
                <?php esc_html_e( 'Auto', 'ltms' ); ?>
            </label>
        </div>
    </div>

    <?php if ( ! $is_restaurant ) : ?>
    <!-- v2.9.92 P2: Improved empty state -->
    <div style="padding:60px;text-align:center;background:#f9fafb;border-radius:12px;border:2px dashed #e5e7eb;">
        <div style="margin-bottom:12px;opacity:0.3;"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 2v8a3 3 0 0 0 6 0V2"/><line x1="8" y1="2" x2="8" y2="10"/><path d="M16 2v20"/><path d="M19 2c-1.5 1.5-3 4-3 7s1.5 3 3 3"/></svg></div>
        <p style="font-size:1.1rem;font-weight:600;margin:0 0 8px;color:#374151;">
            <?php esc_html_e( 'Tu cuenta no tiene el modo restaurante activado.', 'ltms' ); ?>
        </p>
        <p style="font-size:0.85rem;color:#9ca3af;margin:0;">
            <?php esc_html_e( 'Contacta al administrador para activar el Kitchen Display System.', 'ltms' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- v2.9.92 P2: Stats bar with skeleton loading -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#dc2626;" id="ltms-kds-stat-new">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🔴 <?php esc_html_e( 'Nuevos', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#d97706;" id="ltms-kds-stat-preparing">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🟡 <?php esc_html_e( 'Preparando', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#16a34a;" id="ltms-kds-stat-ready">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">🟢 <?php esc_html_e( 'Listos', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#6b7280;" id="ltms-kds-stat-served">
                <span class="ltms-skeleton-loading">—</span>
            </div>
            <div style="font-size:0.75rem;color:#666;">✓ <?php esc_html_e( 'Servidos hoy', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- KDS grid -->
    <div id="ltms-kds-grid" class="ltms-kds-grid" style="
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
        gap:16px;
        min-height:300px;
    ">
        <!-- v2.9.92 P2: Skeleton loading -->
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:#9ca3af;">
            <div style="display:inline-block;width:24px;height:24px;border:3px solid #e5e7eb;border-top:3px solid #2563eb;border-radius:50%;animation:ltms-kds-spin 1s linear infinite;margin-bottom:12px;"></div>
            <div><?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- v2.9.92 P2: Empty state -->
    <div id="ltms-kds-empty" style="display:none;text-align:center;padding:60px 20px;">
        <div style="margin-bottom:12px;opacity:0.3;"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 2v8a3 3 0 0 0 6 0V2"/><line x1="8" y1="2" x2="8" y2="10"/><path d="M16 2v20"/><path d="M19 2c-1.5 1.5-3 4-3 7s1.5 3 3 3"/></svg></div>
        <h3 style="margin:0 0 8px;color:#374151;font-size:1.1rem;"><?php esc_html_e( 'No hay pedidos activos', 'ltms' ); ?></h3>
        <p style="color:#9ca3af;margin:0;font-size:0.85rem;"><?php esc_html_e( 'Los nuevos pedidos aparecerán aquí automáticamente.', 'ltms' ); ?></p>
    </div>

    <!-- Footer bar -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;">
        <span id="ltms-kds-count">—</span>
        <span>
            🕐 <span id="ltms-kds-clock">--:--:--</span>
            · <span id="ltms-kds-last-updated">—</span>
            <!-- v2.9.92 P2: Live indicator -->
            <span id="ltms-kds-live" style="display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%;margin-left:6px;animation:ltms-kds-pulse 2s infinite;" title="<?php esc_attr_e( 'En vivo', 'ltms' ); ?>"></span>
        </span>
    </div>

    <style>
    @keyframes ltms-kds-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes ltms-kds-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
    .ltms-kds-card { background:#fff; border-radius:12px; padding:16px; border-left:4px solid #dc2626; box-shadow:0 1px 4px rgba(0,0,0,0.06); transition: all 0.2s; }
    .ltms-kds-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.1); }
    .ltms-kds-card.status-preparing { border-left-color: #d97706; }
    .ltms-kds-card.status-ready { border-left-color: #16a34a; }
    .ltms-kds-card.status-served { border-left-color: #6b7280; opacity: 0.6; }
    </style>

    <?php
    // FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-kitchen-view.js
    wp_enqueue_script( 'ltms-kitchen-view', LTMS_ASSETS_URL . 'js/ltms-kitchen-view.js', [ 'jquery' ], LTMS_VERSION, true );
    ?>

    <?php endif; ?>
</div>
