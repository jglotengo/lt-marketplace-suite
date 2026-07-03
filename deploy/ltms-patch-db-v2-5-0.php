<?php
/**
 * LTMS Emergency DB Patch v2.5.0
 *
 * Agrega la columna vendor_net a bkr_lt_bookings si no existe.
 * Idempotente: seguro de correr varias veces.
 *
 * Error que resuelve:
 *   WordPress database error Unknown column 'vendor_net' in 'field list'
 *   en ajax_get_vendor_bookings (LTMS_Frontend_Booking_Handler)
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file deploy/ltms-patch-db-v2-5-0.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;
$p = $wpdb->prefix;

echo "=== LTMS DB Patch v2.5.0 ===\n";

// vendor_net en lt_bookings
$col = $wpdb->get_results( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_net'",
    DB_NAME, $p . 'lt_bookings'
) );

if ( empty( $col ) ) {
    $r = $wpdb->query(
        "ALTER TABLE `{$p}lt_bookings`
         ADD COLUMN `vendor_net` DECIMAL(15,2) DEFAULT 0.00
         COMMENT 'Neto al vendedor tras comision'
         AFTER `balance_amount`"
    );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — vendor_net agregado a lt_bookings\n";
} else {
    echo "OK — vendor_net ya existe en lt_bookings\n";
}

// Actualizar version
update_option( 'ltms_db_version', '2.5.0' );
echo "OK ltms_db_version -> 2.5.0\n";
echo "=== Patch v2.5.0 completado ===\n";
