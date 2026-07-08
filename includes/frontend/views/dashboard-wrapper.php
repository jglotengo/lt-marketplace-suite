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

// FIX-P1-BATCH-A: guard against deleted user accounts. get_userdata() returns
// false when the user no longer exists; dereferencing ->display_name would
// fatal. Redirect anonymous/deleted sessions to the login screen instead.
$user_id    = get_current_user_id();
$user       = $user_id ? get_userdata( $user_id ) : null;
if ( ! $user ) {
    wp_safe_redirect( wp_login_url() );
    exit;
}
$store_name   = get_user_meta( $user_id, 'ltms_store_name', true ) ?: $user->display_name;
$wallet       = class_exists( 'LTMS_Business_Wallet' ) ? LTMS_Business_Wallet::get_or_create( $user_id ) : [ 'balance' => 0, 'balance_reserved' => 0 ];
$unread_notif = 0;

// Contar notificaciones no leídas
global $wpdb;
$notif_table  = $wpdb->prefix . 'lt_notifications';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$unread_notif = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$notif_table}` WHERE user_id = %d AND is_read = 0", $user_id ) );

// v2.9.94 P3: SVG icons en nav items (Woodmart-style).
$svg_icons = [
    'home'     => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'orders'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>',
    'products' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
    'envios'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'shipping-statement' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>',
    'wallet'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>',
    'bookings' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'marketing' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a15 15 0 0 1 4 10 15 15 0 0 1-4 10"/><path d="M12 2a15 15 0 0 0-4 10 15 15 0 0 0 4 10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>',
    'security' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'donations' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
    'posgold'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
    'settings' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    'ordenes-compra' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
    'redi'     => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
    'incidents' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'kitchen'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 2v8a3 3 0 0 0 6 0V2"/><line x1="8" y1="2" x2="8" y2="10"/><path d="M16 2v20"/><path d="M19 2c-1.5 1.5-3 4-3 7s1.5 3 3 3"/></svg>',
    'analytics' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    // v2.9.98: SVG icons for insurance + drivers nav items (Woodmart-style, stroke=2).
    'insurance' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L3 7v6c0 5 4 9 9 10 5-1 9-5 9-10V7l-9-5z"/><path d="M9 12l2 2 4-4"/></svg>',
    'drivers'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
];

$nav_items = [
    [ 'view' => 'home',     'icon' => $svg_icons['home'],     'label' => __( 'Inicio', 'ltms' ) ],
    [ 'view' => 'orders',   'icon' => $svg_icons['orders'],   'label' => __( 'Pedidos', 'ltms' ) ],
    [ 'view' => 'products', 'icon' => $svg_icons['products'], 'label' => __( 'Productos', 'ltms' ) ],
    [ 'view' => 'envios',        'icon' => $svg_icons['envios'],   'label' => __( 'Envíos', 'ltms' ) ],
    [ 'view' => 'shipping-statement', 'icon' => $svg_icons['shipping-statement'], 'label' => __( 'Fletes', 'ltms' ) ],
    [ 'view' => 'wallet',   'icon' => $svg_icons['wallet'],   'label' => __( 'Billetera', 'ltms' ) ],
    [ 'view' => 'bookings', 'icon' => $svg_icons['bookings'], 'label' => __( 'Reservas', 'ltms' ) ],
    [ 'view' => 'marketing', 'icon' => $svg_icons['marketing'], 'label' => __( 'Marketing', 'ltms' ) ],
    [ 'view' => 'security', 'icon' => $svg_icons['security'], 'label' => __( 'Seguridad', 'ltms' ) ],
    [ 'view' => 'donations', 'icon' => $svg_icons['donations'], 'label' => __( 'Donaciones', 'ltms' ) ],
    [ 'view' => 'posgold', 'icon' => $svg_icons['posgold'], 'label' => __( 'PosGold', 'ltms' ) ],
    [ 'view' => 'settings', 'icon' => $svg_icons['settings'], 'label' => __( 'Configuración', 'ltms' ) ],
];

// Órdenes de Compra Aveonline: oculto por defecto, controlado desde Configuración > Aveonline.
if ( get_option( 'ltms_ordenes_compra_enabled', 'no' ) === 'yes' ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'ordenes-compra',
        'icon'  => $svg_icons['ordenes-compra'],
        'label' => __( 'Órdenes de Compra', 'ltms' ),
    ]] );
}

// Agregar Analytics solo para premium
if ( in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'analytics',
        'icon'  => $svg_icons['analytics'],
        'label' => __( 'Analytics', 'ltms' ),
    ]] );
}

// AUDIT-REDI-UX-GAPS GAP-1 FIX: tab ReDi en el dashboard del vendedor.
if ( get_option( 'ltms_redi_enabled', 'no' ) === 'yes' ) {
    array_splice( $nav_items, 3, 0, [[
        'view'  => 'redi',
        'icon'  => $svg_icons['redi'],
        'label' => __( 'ReDi', 'ltms' ),
    ]] );
    // GAP-9: tab Novedades.
    array_splice( $nav_items, 5, 0, [[
        'view'  => 'incidents',
        'icon'  => $svg_icons['incidents'],
        'label' => __( 'Novedades', 'ltms' ),
    ]] );
}

// AUDIT-RESTAURANT-ENGINE: tab Kitchen Display para vendors con restaurante.
$_user_id = get_current_user_id();
if ( $_user_id && get_user_meta( $_user_id, 'ltms_is_restaurant', true ) === 'yes' ) {
    array_splice( $nav_items, 4, 0, [[
        'view'  => 'kitchen',
        'icon'  => $svg_icons['kitchen'],
        'label' => __( 'Cocina', 'ltms' ),
    ]] );
}

// v2.9.98: tab Seguros siempre visible para vendors (transparencia sobre pólizas XCover).
if ( file_exists( __DIR__ . '/view-insurance.php' ) ) {
    // Insertar después de wallet (índice del bloque wallet varía según nav_items previos).
    $_wallet_idx = array_search( 'wallet', array_column( $nav_items, 'view' ), true );
    if ( false !== $_wallet_idx ) {
        array_splice( $nav_items, $_wallet_idx + 1, 0, [[
            'view'  => 'insurance',
            'icon'  => $svg_icons['insurance'],
            'label' => __( 'Seguros', 'ltms' ),
        ]] );
    }
}

// v2.9.98: tab Domiciliarios si el vendor tiene own-delivery configurado o repartidores registrados.
$_show_drivers = false;
if ( $_user_id && file_exists( __DIR__ . '/view-drivers.php' ) ) {
    $_delivery_zones = (string) get_user_meta( $_user_id, 'ltms_own_delivery_zones', true );
    // Quick check: any driver row? Use cached count first (set on save).
    $_drivers_count = (int) get_user_meta( $_user_id, '_ltms_drivers_count_cache', true );
    if ( $_drivers_count > 0 || '' !== $_delivery_zones ) {
        $_show_drivers = true;
    } else {
        // Fallback: actual DB count (only when cache is empty).
        global $wpdb;
        $_drivers_table = $wpdb->prefix . 'lt_vendor_drivers';
        $_drivers_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$_drivers_table}` WHERE vendor_id = %d", $_user_id ) ); // phpcs:ignore
        if ( $_drivers_count > 0 ) {
            update_user_meta( $_user_id, '_ltms_drivers_count_cache', $_drivers_count );
            $_show_drivers = true;
        }
    }
}
if ( $_show_drivers ) {
    // Insertar después de envíos (envios está en el nav principal).
    $_envios_idx = array_search( 'envios', array_column( $nav_items, 'view' ), true );
    if ( false !== $_envios_idx ) {
        array_splice( $nav_items, $_envios_idx + 1, 0, [[
            'view'  => 'drivers',
            'icon'  => $svg_icons['drivers'],
            'label' => __( 'Domiciliarios', 'ltms' ),
        ]] );
    } else {
        // Fallback: al final del nav (antes de settings).
        $_settings_idx = array_search( 'settings', array_column( $nav_items, 'view' ), true );
        if ( false !== $_settings_idx ) {
            array_splice( $nav_items, $_settings_idx, 0, [[
                'view'  => 'drivers',
                'icon'  => $svg_icons['drivers'],
                'label' => __( 'Domiciliarios', 'ltms' ),
            ]] );
        }
    }
}
?>
<div class="ltms-dashboard-container" id="ltms-dashboard-container">

    <!-- v2.9.95 P3: Accessibility skip-link (CSS-only, no inline handlers) -->
    <a href="#ltms-main-content" class="ltms-skip-link"><?php esc_html_e( 'Saltar al contenido principal', 'ltms' ); ?></a>

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
                <span class="ltms-nav-icon"><?php echo $item['icon']; // phpcs:ignore — SVG icons are hardcoded, safe ?></span>
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

            <!-- v2.9.84 P1: Global search (Woodmart-inspired) -->
            <div class="ltms-topbar-search" style="flex:1;max-width:300px;margin:0 16px;display:none;" id="ltms-topbar-search-wrap">
                <div style="position:relative;">
                    <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:#9ca3af;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" id="ltms-topbar-search-input" placeholder="<?php esc_attr_e( 'Buscar en el panel...', 'ltms' ); ?>"
                           style="width:100%;padding:7px 12px 7px 32px;border:1px solid #d1d5db;border-radius:8px;font-size:0.82rem;background:#f9fafb;"
                           aria-label="<?php esc_attr_e( 'Buscar', 'ltms' ); ?>">
                </div>
            </div>
            <style>@media (min-width:769px){#ltms-topbar-search-wrap{display:block !important;}}</style>

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
                <button type="button" style="background:none;border:none;cursor:pointer;font-size:1rem;" data-action="close-notif-panel" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>">✕</button>
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

        <?php
        // v2.9.98: Vista Seguros — siempre incluida (transparencia sobre pólizas XCover).
        if ( file_exists( __DIR__ . '/view-insurance.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-insurance" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-insurance.php' ) ) { try { include __DIR__ . '/view-insurance.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>
        <?php endif; ?>

        <?php
        // v2.9.98: Vista Domiciliarios — incluida solo cuando el vendor la necesita ( coincide con nav ).
        if ( $_show_drivers && file_exists( __DIR__ . '/view-drivers.php' ) ) :
        ?>
        <div class="ltms-view-section" id="ltms-view-drivers" style="display:none;">
            <?php if ( file_exists( __DIR__ . '/view-drivers.php' ) ) { try { include __DIR__ . '/view-drivers.php'; } catch ( \Throwable $e ) { echo '<div class="ltms-notice ltms-notice-error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>'; } } ?>
        </div>
        <?php endif; ?>

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
    /* v2.9.94 P3: Focus-visible outlines en nav items */
    .ltms-nav-item:focus-visible, .ltms-bottom-nav-item:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .ltms-topbar-notif:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; border-radius: 8px; }
    /* v2.9.94 P3: Nav icon sizing for SVG */
    .ltms-nav-icon svg { width: 20px; height: 20px; display: block; }
    .ltms-bottom-nav-icon svg { width: 22px; height: 22px; display: block; }
    /* v2.9.95 P3: Skip-link (CSS-only, CSP compliant) */
    .ltms-skip-link { position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; overflow: hidden; z-index: 99999; }
    .ltms-skip-link:focus { position: fixed; top: 0; left: 0; width: auto; height: auto; padding: 10px 20px; background: #2563eb; color: #fff !important; border-radius: 0 0 8px 0; font-size: 0.85rem; font-weight: 600; text-decoration: none; }
    </style>

    <!-- v2.9.94 P3: Keyboard shortcut help modal -->
    <div id="ltms-shortcuts-modal" class="ltms-modal" style="display:none;">
        <div class="ltms-modal-backdrop" data-action="close-shortcuts"></div>
        <div class="ltms-modal-inner" style="max-width:480px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;font-size:1.1rem;">⌨️ <?php esc_html_e( 'Atajos de Teclado', 'ltms' ); ?></h3>
                <button type="button" data-action="close-shortcuts" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
            </div>
            <table style="width:100%;font-size:0.85rem;">
                <tbody>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">g + h</kbd></td><td><?php esc_html_e( 'Inicio', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">g + o</kbd></td><td><?php esc_html_e( 'Pedidos', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">g + p</kbd></td><td><?php esc_html_e( 'Productos', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">g + w</kbd></td><td><?php esc_html_e( 'Billetera', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">g + s</kbd></td><td><?php esc_html_e( 'Configuración', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">/</kbd></td><td><?php esc_html_e( 'Buscar', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">Esc</kbd></td><td><?php esc_html_e( 'Cerrar modales', 'ltms' ); ?></td></tr>
                    <tr><td style="padding:6px 0;"><kbd style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-family:monospace;">?</kbd></td><td><?php esc_html_e( 'Esta ayuda', 'ltms' ); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /ltms-dashboard-container -->
 
