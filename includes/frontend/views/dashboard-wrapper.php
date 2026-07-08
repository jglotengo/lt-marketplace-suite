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
$wallet       = class_exists( 'LTMS_Business_Wallet' ) ? LTMS_Business_Wallet::get_or_create( $user_id ) : [ 'balance' => 0, 'balance_reserved' => 0 ];
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
    [ 'view' => 'envios',        'icon' => '🚚', 'label' => __( 'Envíos', 'ltms' ) ],
    [ 'view' => 'shipping-statement', 'icon' => '🧾', 'label' => __( 'Fletes', 'ltms' ) ],
    [ 'view' => 'wallet',   'icon' => '💰', 'label' => __( 'Billetera', 'ltms' ) ],
    [ 'view' => 'bookings', 'icon' => '🏨', 'label' => __( 'Reservas', 'ltms' ) ],
    [ 'view' => 'marketing', 'icon' => '🎨', 'label' => __( 'Marketing', 'ltms' ) ],
    [ 'view' => 'security', 'icon' => '🔐', 'label' => __( 'Seguridad', 'ltms' ) ],
    [ 'view' => 'donations', 'icon' => '❤️', 'label' => __( 'Donaciones', 'ltms' ) ],
    [ 'view' => 'posgold', 'icon' => '🔗', 'label' => __( 'PosGold', 'ltms' ) ],
    [ 'view' => 'settings', 'icon' => '⚙️', 'label' => __( 'Configuración', 'ltms' ) ],
];

// Órdenes de Compra Aveonline: oculto por defecto, controlado desde Configuración > Aveonline.
if ( get_option( 'ltms_ordenes_compra_enabled', 'no' ) === 'yes' ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'ordenes-compra',
        'icon'  => '🛒',
        'label' => __( 'Órdenes de Compra', 'ltms' ),
    ]] );
}

// Agregar Analytics solo para premium
if ( in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'analytics',
        'icon'  => '📈',
        'label' => __( 'Analytics', 'ltms' ),
    ]] );
}

// AUDIT-REDI-UX-GAPS GAP-1 FIX: tab ReDi en el dashboard del vendedor.
if ( get_option( 'ltms_redi_enabled', 'no' ) === 'yes' ) {
    array_splice( $nav_items, 3, 0, [[
        'view'  => 'redi',
        'icon'  => '🔁',
        'label' => __( 'ReDi', 'ltms' ),
    ]] );
    // GAP-9: tab Novedades.
    array_splice( $nav_items, 5, 0, [[
        'view'  => 'incidents',
        'icon'  => '⚠️',
        'label' => __( 'Novedades', 'ltms' ),
    ]] );
}

// AUDIT-RESTAURANT-ENGINE: tab Kitchen Display para vendors con restaurante.
$_user_id = get_current_user_id();
if ( $_user_id && get_user_meta( $_user_id, 'ltms_is_restaurant', true ) === 'yes' ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'kitchen',
        'icon'  => '🍳',
        'label' => __( 'Cocina', 'ltms' ),
    ]] );
}
?>
<div class="ltms-dashboard-container" id="ltms-dashboard-container">

    <!-- Sidebar -->
    <div class="ltms-sidebar-overlay" id="ltms-sidebar-overlay"></div>
    <aside class="ltms-sidebar" id="ltms-sidebar">
        <div class="ltms-sidebar-logo">
            <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
            <button type="button" class="ltms-sidebar-close-btn" aria-label="Cerrar menú">&#x2715;</button>
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
            <button type="button" class="ltms-mobile-menu-btn" id="ltms-mobile-menu-btn">☰</button>
            <h2 class="ltms-topbar-title"><?php esc_html_e( 'Inicio', 'ltms' ); ?></h2>

            <div class="ltms-topbar-actions">
                <!-- Notificaciones -->
                <div class="ltms-topbar-notif"
                     role="button"
                     tabindex="0"
                     aria-expanded="false"
                     aria-label="<?php esc_attr_e( 'Notificaciones', 'ltms' ); ?>"
                     id="ltms-notif-bell">
                    🔔
                    <?php if ( $unread_notif > 0 ) : ?>
                    <span class="ltms-badge-count" aria-label="<?php echo esc_attr( sprintf( _n( '%d notificación no leída', '%d notificaciones no leídas', $unread_notif, 'ltms' ), $unread_notif ) ); ?>"><?php echo esc_html( min( 99, $unread_notif ) ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Usuario -->
                <div style="font-size:0.85rem;color:#374151;">
                    <?php echo esc_html( $store_name ); ?>
                </div>

                <!-- Logout -->
                <a href="<?php echo esc_url( wp_logout_url( home_url( '/login-vendedor/' ) ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="text-decoration:none;">Salir</a>
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
            <?php if ( file_exists( __DIR__ . '/view-home.php' ) ) { try { include __DIR__ . '/view-home.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error al cargar Inicio: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-orders" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-orders.php' ) ) { try { include __DIR__ . '/view-orders.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-products" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-products.php' ) ) { try { include __DIR__ . '/view-products.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-wallet" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-wallet.php' ) ) { try { include __DIR__ . '/view-wallet.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-settings" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-settings.php' ) ) { try { include __DIR__ . '/view-settings.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-envios" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-envios.php' ) ) { try { include __DIR__ . '/view-envios.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <?php
        // v2.8.3 — Shipping Cost Ledger: estado de cuenta de fletes absorbed.
        if ( file_exists( __DIR__ . '/view-shipping-statement.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-shipping-statement" style="display:none;">
            <?php include __DIR__ . '/view-shipping-statement.php'; ?>
        </div>
        <?php endif; ?>

        <?php
        // AUDIT-REDI-UX-GAPS GAP-1 FIX: incluir view-redi.php cuando está habilitado.
        if ( get_option( 'ltms_redi_enabled', 'no' ) === 'yes' && file_exists( __DIR__ . '/view-redi.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-redi" style="display:none;">
            <?php include __DIR__ . '/view-redi.php'; ?>
        </div>
        <?php endif; ?>

        <?php
        // AUDIT-REDI-UX-GAPS GAP-9 FIX: incluir view-incidents.php.
        if ( get_option( 'ltms_redi_enabled', 'no' ) === 'yes' && file_exists( __DIR__ . '/view-incidents.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-incidents" style="display:none;">
            <?php include __DIR__ . '/view-incidents.php'; ?>
        </div>
        <?php endif; ?>

        <?php
        // AUDIT-RESTAURANT-ENGINE: incluir view-kitchen.php para vendors con restaurante.
        $_uid = get_current_user_id();
        if ( $_uid && get_user_meta( $_uid, 'ltms_is_restaurant', true ) === 'yes' && file_exists( __DIR__ . '/view-kitchen.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-kitchen" style="display:none;">
            <?php include __DIR__ . '/view-kitchen.php'; ?>
        </div>
        <?php endif; ?>

        <div class="ltms-view-section" id="ltms-view-ordenes-compra" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-ordenes-compra.php' ) ) { try { include __DIR__ . '/view-ordenes-compra.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-bookings" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-bookings.php' ) ) { try { include __DIR__ . '/view-bookings.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-marketing" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-marketing.php' ) ) { try { include __DIR__ . '/view-marketing.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-security" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-security.php' ) ) { try { include __DIR__ . '/view-security.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-donations" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-donations.php' ) ) { try { include __DIR__ . '/view-donations.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>

        <div class="ltms-view-section" id="ltms-view-posgold" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-posgold.php' ) ) { try { include __DIR__ . '/view-posgold.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
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

    <!-- v2.9.78 P1: Mobile bottom navigation (Woodmart-inspired) -->
    <nav class="ltms-bottom-nav" aria-label="<?php esc_attr_e( 'Navegación rápida', 'ltms' ); ?>">
        <button class="ltms-bottom-nav-item active" data-view="home" aria-label="<?php esc_attr_e( 'Inicio', 'ltms' ); ?>">
            <span class="ltms-bottom-nav-icon">📊</span>
            <span class="ltms-bottom-nav-label"><?php esc_html_e( 'Inicio', 'ltms' ); ?></span>
        </button>
        <button class="ltms-bottom-nav-item" data-view="orders" aria-label="<?php esc_attr_e( 'Pedidos', 'ltms' ); ?>">
            <span class="ltms-bottom-nav-icon">📦</span>
            <span class="ltms-bottom-nav-label"><?php esc_html_e( 'Pedidos', 'ltms' ); ?></span>
        </button>
        <button class="ltms-bottom-nav-item" data-view="products" aria-label="<?php esc_attr_e( 'Productos', 'ltms' ); ?>">
            <span class="ltms-bottom-nav-icon">🛍️</span>
            <span class="ltms-bottom-nav-label"><?php esc_html_e( 'Productos', 'ltms' ); ?></span>
        </button>
        <button class="ltms-bottom-nav-item" data-view="wallet" aria-label="<?php esc_attr_e( 'Billetera', 'ltms' ); ?>">
            <span class="ltms-bottom-nav-icon">💰</span>
            <span class="ltms-bottom-nav-label"><?php esc_html_e( 'Billetera', 'ltms' ); ?></span>
        </button>
        <button class="ltms-bottom-nav-item" data-view="settings" aria-label="<?php esc_attr_e( 'Ajustes', 'ltms' ); ?>">
            <span class="ltms-bottom-nav-icon">⚙️</span>
            <span class="ltms-bottom-nav-label"><?php esc_html_e( 'Ajustes', 'ltms' ); ?></span>
        </button>
    </nav>

    <style>
    /* v2.9.78 P1: Mobile bottom nav styles */
    .ltms-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 999; background: #fff; border-top: 1px solid #e5e7eb; padding: 6px 0; justify-content: space-around; box-shadow: 0 -2px 10px rgba(0,0,0,0.06); }
    .ltms-bottom-nav-item { background: none; border: none; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 6px 10px; border-radius: 8px; color: #6b7280; font-size: 0.65rem; transition: all 0.2s; }
    .ltms-bottom-nav-item.active { color: #2563eb; background: #eff6ff; }
    .ltms-bottom-nav-icon { font-size: 1.3rem; line-height: 1; }
    .ltms-bottom-nav-label { font-weight: 600; }
    @media (max-width: 768px) {
        .ltms-bottom-nav { display: flex; }
        .ltms-main-content { padding-bottom: 70px !important; }
    }
    /* v2.9.78 P1: Skeleton loading animation */
    .ltms-skeleton-loading { animation: ltms-pulse 1.5s ease-in-out infinite; opacity: 0.6; }
    @keyframes ltms-pulse { 0%,100% { opacity: 0.6; } 50% { opacity: 1; } }
    /* v2.9.82 P2: Dark mode */
    .ltms-dark-mode { --ltms-bg: #1a1a2e; --ltms-card-bg: #16213e; --ltms-text: #e2e8f0; --ltms-text-muted: #94a3b8; --ltms-border: #334155; }
    .ltms-dark-mode .ltms-main-content { background: var(--ltms-bg) !important; }
    .ltms-dark-mode .ltms-card, .ltms-dark-mode .ltms-modal-inner { background: var(--ltms-card-bg) !important; border-color: var(--ltms-border) !important; }
    .ltms-dark-mode .ltms-dtable th { background: #0f172a !important; color: var(--ltms-text) !important; }
    .ltms-dark-mode .ltms-dtable td { color: var(--ltms-text) !important; border-color: var(--ltms-border) !important; }
    .ltms-dark-mode .ltms-topbar { background: var(--ltms-card-bg) !important; }
    .ltms-dark-mode .ltms-topbar-title, .ltms-dark-mode .ltms-view-header h2 { color: var(--ltms-text) !important; }
    .ltms-dark-mode .ltms-sidebar { background: var(--ltms-card-bg) !important; }
    .ltms-dark-mode .ltms-nav-item { color: var(--ltms-text-muted) !important; }
    .ltms-dark-mode .ltms-nav-item.active { color: #60a5fa !important; background: rgba(96,165,250,0.1) !important; }
    .ltms-dark-mode .ltms-bottom-nav { background: var(--ltms-card-bg) !important; border-color: var(--ltms-border) !important; }
    .ltms-dark-mode input, .ltms-dark-mode textarea, .ltms-dark-mode select { background: #0f172a !important; color: var(--ltms-text) !important; border-color: var(--ltms-border) !important; }
    /* v2.9.82 P2: Breadcrumbs */
    .ltms-breadcrumbs { display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #9ca3af; padding: 0 0 12px; }
    .ltms-breadcrumbs a { color: #6b7280; text-decoration: none; }
    .ltms-breadcrumbs a:hover { color: #2563eb; }
    .ltms-breadcrumbs span { color: #111827; font-weight: 600; }
    </style>

</div><!-- /ltms-dashboard-container -->
 
