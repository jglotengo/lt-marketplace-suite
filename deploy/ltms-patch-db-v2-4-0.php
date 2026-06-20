<?php
/**
 * LTMS Emergency DB Patch v2.4.0
 *
 * Agrega vendor_id a lt_booking_season_rules y policy_id a lt_bookings.
 * Idempotente: seguro de correr varias veces.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/deploy/ltms-patch-db-v2-4-0.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;
$p = $wpdb->prefix;

echo "=== LTMS DB Patch v2.4.0 ===\n";

// 1. vendor_id en lt_booking_season_rules
$col = $wpdb->get_results( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_id'",
    DB_NAME, $p . 'lt_booking_season_rules'
) );

if ( empty( $col ) ) {
    $r = $wpdb->query( "ALTER TABLE `{$p}lt_booking_season_rules` ADD COLUMN `vendor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Vendedor dueno de la regla' AFTER `product_id`, ADD INDEX `idx_vendor_id` (`vendor_id`)" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " vendor_id agregado a lt_booking_season_rules\n";

    $wpdb->query( "UPDATE `{$p}lt_booking_season_rules` AS sr JOIN `{$p}posts` AS p ON p.ID = sr.product_id SET sr.vendor_id = p.post_author WHERE sr.product_id > 0 AND sr.vendor_id = 0" );
    echo "OK Backfill vendor_id: {$wpdb->rows_affected} filas actualizadas\n";
} else {
    echo "OK vendor_id ya existe en lt_booking_season_rules\n";
}

// 2. policy_id en lt_bookings
$col2 = $wpdb->get_results( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'policy_id'",
    DB_NAME, $p . 'lt_bookings'
) );

if ( empty( $col2 ) ) {
    $r2 = $wpdb->query( "ALTER TABLE `{$p}lt_bookings` ADD COLUMN `policy_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a bkr_lt_booking_policies' AFTER `vendor_net`, ADD INDEX `idx_policy_id` (`policy_id`)" );
    echo ( false !== $r2 ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " policy_id agregado a lt_bookings\n";
} else {
    echo "OK policy_id ya existe en lt_bookings\n";
}

// 3. Actualizar version
update_option( 'ltms_db_version', '2.4.0' );
echo "OK ltms_db_version -> 2.4.0\n";
echo "=== Patch v2.4.0 completado ===\n";
