<?php
/**
 * LTMS Backfill — store slugs de vendedores
 *
 * Asigna ltms_store_slug a todos los vendedores existentes que no lo
 * tengan, a partir del nombre de su tienda (o su login/display_name como
 * respaldo). Necesario porque muchos vendedores legacy tienen user_login
 * con caracteres no aptos para URL (ej. "marco@dominio.com").
 *
 * Idempotente — seguro de correr varias veces; solo toca vendedores sin
 * slug guardado.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file deploy/ltms-backfill-store-slugs.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI: wp eval-file deploy/ltms-backfill-store-slugs.php' );
}

if ( ! class_exists( 'LTMS_Vendor_Storefront' ) ) {
    echo "ERROR: LTMS_Vendor_Storefront no existe. ¿El plugin está activo?\n";
    return;
}

echo "=== LTMS Backfill: store slugs de vendedores ===\n";

$vendors = get_users( [ 'role' => 'ltms_vendor', 'number' => -1 ] );
echo 'Total vendedores: ' . count( $vendors ) . "\n";

$assigned = 0;
$skipped  = 0;

foreach ( $vendors as $v ) {
    $existing = get_user_meta( $v->ID, 'ltms_store_slug', true );
    if ( $existing ) {
        $skipped++;
        continue;
    }

    $store_name = get_user_meta( $v->ID, 'ltms_store_name', true ) ?: $v->display_name ?: $v->user_login;
    $slug       = LTMS_Vendor_Storefront::generate_unique_slug( $store_name, $v->ID );

    update_user_meta( $v->ID, 'ltms_store_slug', $slug );
    echo "OK  vendor {$v->ID} ({$v->user_login}) -> /vendedor/{$slug}/\n";
    $assigned++;
}

echo "\nAsignados: {$assigned}\n";
echo "Ya tenían slug (omitidos): {$skipped}\n";
echo "=== Backfill completado ===\n";
