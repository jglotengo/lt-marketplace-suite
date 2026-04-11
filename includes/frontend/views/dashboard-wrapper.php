<?php
/**
 * Vista: Dashboard Wrapper - Contenedor Principal SPA del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id      = get_current_user_id();
$user         = get_userdata( $user_id );
$store_name   = get_user_meta( $user_id, 'ltms_store_name', true ) ?: $user->display_name;
$wallet       = LTMS_Business_Wallet::get_or_create( $user_id );
$unread_notif = 0;

// Contar notificaciones no leídas
global $wpdb;
$notif_table  = $wpdb->prefix . 'lt_notifications';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$unread_notif = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$notif_table}` WHERE user_id = %d AND is_read = 0", $user_id ) );

$nav_items = [
    [ 'view' => 'home',     'icon' => '📊', 'label' => __( 'Inicio', 'ltms' ) ],
    [ 'view' => 'orders',   'icon' => '📦', 'label' => __( 'Pedidos', 'ltms' ) ],
    [ 'view' => 'products', 'icon' => '🛍️', 'label' => __( 'Productos', 'ltms' ) ],
    [ 'view' => 'wallet',   'icon' => '💰', 'label' => __( 'Billetera', 'ltms' ) ],
    [ 'view' => 'settings', 'icon' => '⚙️', 'label' => __( 'Configuración', 'ltms' ) ],
];

// Agregar Analytics solo para premium
if ( in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'analytics',
        'icon'  => '📈',
        'label' => __( 'Analytics', 'ltms' ),
    ]] );
}
?>
<div class="ltms-dashboard-container" id="ltms-dashboard-container">

    <!-- Sidebar -->
    <aside class="ltms-sidebar" id="ltms-sidebar">
        <div class="ltms-sidebar-logo">
            <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
        </div>

        <nav class="ltms-sidebar-nav">
            <?php foreach ( $nav_items as $item ) : ?>
            <button type="button"
                    class="ltms-nav-item <?php echo $item['view'] === 'home' ? 'active' : ''; ?>"
                    data-view="<?php echo esc_attr( $item['view'] ); ?>">
                <span class="ltms-nav-icon"><?php echo esc_html( $item['icon'] ); ?></span>
                <span class="ltms-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
            </button>
            <?php endforeach; ?>
        </nav>

        <!-- Balance en sidebar -->
        <div style="padding:16px;border-top:1px solid rgba(255,255,255,0.1);margin-top:auto">
            <div style="font-size:0.7rem;opacity:0.7;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">
                <?php esc_html_e( 'Balance Disponible', 'ltms' ); ?>
            </div>
            <div style="font-size:1.1rem;font-weight:700;">
                <?php echo esc_html( LTMS_Utils::format_money( (float) $wallet['balance'] ) ); ?>
            </div>
        </div>
    </aside>

    <!-- Contenido principal -->
    <main class="ltms-main-content" id="ltms-main-content">

        <!-- Topbar -->
        <header class="ltms-topbar">
            <button type="button" class="ltms-mobile-menu-btn" style="display:none;background:none;border:none;cursor:pointer;font-size:1.2rem;">☰</button>
            <h2 class="ltms-topbar-title"><?php esc_html_e( 'Inicio', 'ltms' ); ?></h2>

            <div class="ltms-topbar-actions">
                <!-- Notificaciones -->
                <div class="ltms-topbar-notif">
                    🔔
                    <?php if ( $unread_notif > 0 ) : ?>
                    <span class="ltms-badge-count"><?php echo esc_html( min( 99, $unread_notif ) ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Usuario -->
                <div style="font-size:0.85rem;color:#374151;">
                    <?php echo esc_html( $store_name ); ?>
                </div>

                <!-- Logout -->
                <button type="button" class="ltms-logout-btn ltms-btn ltms-btn-outline ltms-btn-sm">
                    <?php esc_html_e( 'Salir', 'ltms' ); ?>
                </button>
            </div>
        </header>

        <!-- Panel de notificaciones -->
        <div class="ltms-notifications-panel" id="ltms-notif-panel">
            <div class="ltms-notif-header">
                <span><?php esc_html_e( 'Notificaciones', 'ltms' ); ?></span>
                <button type="button" style="background:none;border:none;cursor:pointer;font-size:1rem;" onclick="$('.ltms-notifications-panel').removeClass('open')">✕</button>
            </div>
            <div id="ltms-notif-list">
                <?php if ( $unread_notif === 0 ) : ?>
                <div class="ltms-notif-item" style="color:#9ca3af;text-align:center;padding:24px 16px;">
                    <?php esc_html_e( 'Sin notificaciones nuevas', 'ltms' ); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vistas del SPA -->
        <div class="ltms-view-section" id="ltms-view-home" style="display:none;">
            <?php include __DIR__ . '/view-home.php'; ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-orders" style="display:none;">
            <?php include __DIR__ . '/view-orders.php'; ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-products" style="display:none;">
            <?php include __DIR__ . '/view-products.php'; ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-wallet" style="display:none;">
            <?php include __DIR__ . '/view-wallet.php'; ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-settings" style="display:none;">
            <?php include __DIR__ . '/view-settings.php'; ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-analytics" style="display:none;">
            <div class="ltms-view-section">
                <div class="ltms-view-header">
                    <h2><?php esc_html_e( 'Analytics Avanzados', 'ltms' ); ?></h2>
                </div>
                <div class="ltms-card">
                    <div class="ltms-card-body">
                        <div style="height:350px;"><canvas id="ltms-vendor-analytics-chart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

    </main><!-- /ltms-main-content -->

</div><!-- /ltms-dashboard-container -->
 
