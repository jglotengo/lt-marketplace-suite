<?php
/**
 * LTMS Repair: regenera ltms_installed_pages mapeando key → page_id por SLUG.
 *
 * Útil cuando las páginas existen físicamente pero ltms_installed_pages está
 * vacío, desactualizado o apuntando a páginas eliminadas.
 *
 * Ejecutar: wp --path=/wp eval-file bin/ltms-repair-pages.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    echo "Este script debe ejecutarse vía WP-CLI.\n";
    exit( 1 );
}

// Mapeo definitivo key → slug. DEBE coincidir con LTMS_Activator::create_required_pages().
// M-57: updated to match LTMS_Activator::create_required_pages() canonical keys.
$key_to_slug = [
    'ltms-sellers'         => 'sellers',           // landing captación vendedores (M-55)
    'ltms-dashboard'       => 'panel-vendedor',
    'ltms-login'           => 'login-vendedor',
    'ltms-register'        => 'registro-vendedor',  // clave canónica (era ltms-vendor-register)
    'ltms-vendor-register' => 'registro-vendedor',  // alias legacy — mismo slug
    'ltms-store'           => 'tienda',
    'ltms-orders'          => 'mis-pedidos',
    'ltms-wallet'          => 'mi-billetera',
    'ltms-kyc'             => 'verificacion-identidad',
    'ltms-insurance'       => 'mis-seguros',
];

$installed = get_option( 'ltms_installed_pages', [] );
if ( ! is_array( $installed ) ) {
    $installed = [];
}

echo "==========================================\n";
echo " LTMS Repair: ltms_installed_pages\n";
echo "==========================================\n";
echo "Estado actual de ltms_installed_pages:\n";
print_r( $installed );
echo "\n";

$updated = false;
foreach ( $key_to_slug as $key => $slug ) {
    $current_id = (int) ( $installed[ $key ] ?? 0 );
    $current_post = $current_id ? get_post( $current_id ) : null;

    // Buscar página real por slug.
    $page = get_page_by_path( $slug );

    // Fallback: si get_page_by_path falla (slugs alternos con sufijo numérico),
    // intentar con sufijo -2 (caso del slug 'tienda' colisionando con WooCommerce).
    if ( ! $page && $slug === 'tienda' ) {
        $page = get_page_by_path( 'tienda-2' );
    }

    if ( ! $page ) {
        echo sprintf( "  ⚠️  %s — slug '%s': PÁGINA NO ENCONTRADA en BD. Considera regenerar.\n", $key, $slug );
        continue;
    }

    $real_id = (int) $page->ID;

    if ( $current_id === $real_id && $current_post ) {
        echo sprintf( "  ✅  %s — slug '%s' → ID %d (sin cambio)\n", $key, $slug, $real_id );
        continue;
    }

    $installed[ $key ] = $real_id;
    $updated = true;
    echo sprintf( "  🔧  %s — slug '%s' → ID %d (era %d)\n", $key, $slug, $real_id, $current_id );
}

if ( $updated ) {
    update_option( 'ltms_installed_pages', $installed );
    echo "\n✅ ltms_installed_pages ACTUALIZADO.\n";
} else {
    echo "\n✓ ltms_installed_pages ya estaba correcto, sin cambios.\n";
}

echo "\nEstado final:\n";
print_r( get_option( 'ltms_installed_pages' ) );

// Limpiar OPcache para que los cambios PHP entren en efecto inmediato.
if ( function_exists( 'opcache_reset' ) ) {
    @opcache_reset();
    echo "\n✓ OPcache reseteado.\n";
}
