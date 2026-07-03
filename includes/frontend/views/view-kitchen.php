<?php
/**
 * Vista: Kitchen Display System (KDS)
 *
 * AUDIT-RESTAURANT-ENGINE FIX: esta vista NO existía — el PHP comentaba
 * "consumed by view-kitchen.php" pero el archivo nunca fue creado.
 * El KDS solo funcionaba si se accedía via ?tab=kds pero no había HTML.
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
            <button type="button" id="ltms-kds-sound-toggle" class="ltms-btn ltms-btn-outline ltms-btn-sm">🔔 <?php esc_html_e( 'Sonido: ON', 'ltms' ); ?></button>
            <button type="button" id="ltms-kds-refresh-btn" class="ltms-btn ltms-btn-outline ltms-btn-sm">🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?></button>
        </div>
    </div>

    <?php if ( ! $is_restaurant ) : ?>
    <div style="padding:40px;text-align:center;background:#f9f9f9;border-radius:12px;color:#666;">
        <p style="font-size:2rem;margin-bottom:12px;">🍽️</p>
        <p style="font-size:1.1rem;font-weight:600;margin-bottom:8px;">
            <?php esc_html_e( 'Tu cuenta no tiene el modo restaurante activado.', 'ltms' ); ?>
        </p>
        <p style="font-size:0.85rem;">
            <?php esc_html_e( 'Contacta al administrador para activar el Kitchen Display System.', 'ltms' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- Stats bar -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#dc2626;" id="ltms-kds-stat-new">—</div>
            <div style="font-size:0.75rem;color:#666;">🔴 Nuevos</div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#d97706;" id="ltms-kds-stat-preparing">—</div>
            <div style="font-size:0.75rem;color:#666;">🟡 Preparando</div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#16a34a;" id="ltms-kds-stat-ready">—</div>
            <div style="font-size:0.75rem;color:#666;">🟢 Listos</div>
        </div>
        <div class="ltms-card" style="padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:700;color:#6b7280;" id="ltms-kds-stat-served">—</div>
            <div style="font-size:0.75rem;color:#666;">✓ Servidos hoy</div>
        </div>
    </div>

    <!-- KDS grid -->
    <div id="ltms-kds-grid" class="ltms-kds-grid" style="
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
        gap:16px;
        min-height:300px;
    ">
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:#9ca3af;">
            <?php esc_html_e( 'Cargando pedidos...', 'ltms' ); ?>
        </div>
    </div>

    <!-- Footer bar -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 4px;font-size:.85rem;color:#6b7280;">
        <span id="ltms-kds-count">—</span>
        <span>
            🕐 <span id="ltms-kds-clock">--:--:--</span>
            · <span id="ltms-kds-last-updated">—</span>
        </span>
    </div>

    <?php endif; ?>
</div>
